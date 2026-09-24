<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Router;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\FlightRepository;
use App\Repositories\MedicalRepository;
use App\Repositories\UserRepository;
use App\Services\AttachmentService;
use App\Services\LeadService;
use App\Services\MedicalService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Medical certificates and flight tickets: uploaded through the document pipeline, linked from the record. */
final class AttachmentTest extends DbTestCase
{
    private int $branchId;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];
    /** @var list<string> */
    private array $tmp = [];
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM document_types') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->branchId = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'AT branch', 'code' => 'ATX-' . bin2hex(random_bytes(2))]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'ATX-%')";
        foreach ($this->db->select("SELECT storage_path FROM candidate_documents WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})") as $d) {
            @unlink($this->app->basePath((string) $d['storage_path']));
        }
        $cand = "candidate_id IN (SELECT id FROM candidates WHERE {$like})";
        $this->db->affectingStatement("DELETE FROM medical_records WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM flight_bookings WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM candidate_documents WHERE {$cand}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('leads', 'candidates', 'medical', 'travel')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        if ($this->personIds !== []) {
            $ph = implode(',', array_fill(0, count($this->personIds), '?'));
            $this->db->affectingStatement("DELETE FROM persons WHERE id IN ({$ph})", $this->personIds);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'ATX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%' OR scope LIKE 'candidate:%'");
    }

    private function actor(string $role = 'manager'): User
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "AT {$role}", 'email' => 'at_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branchId]);
        $this->userIds[] = $id;

        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function candidate(User $actor): Candidate
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'AT Cand', 'phone' => '95' . str_pad((string) (random_int(1000, 9999) * 10000 + ++$this->seq), 8, '0', STR_PAD_LEFT), 'priority' => 'medium'], $actor, $this->branchId, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    /** A medical that has been attended (so a certificate may be attached). */
    private function medical(Candidate $c, User $actor, bool $attended = true): \App\Models\MedicalRecord
    {
        $svc = $this->app->get(MedicalService::class);
        $m = $svc->book($c, null, ['medical_center' => 'City Clinic', 'appointment_date' => gmdate('Y-m-d', strtotime('+1 day')), 'notes' => null], $actor);

        return $attended ? $svc->markAttended($m, gmdate('Y-m-d'), $actor) : $m;
    }

    private function flight(Candidate $c, string $status = 'booked'): \App\Models\FlightBooking
    {
        $id = (int) $this->db->insertRow('flight_bookings', ['public_id' => Ulid::generate(), 'candidate_id' => $c->id, 'created_by' => $this->userIds[0], 'status' => $status, 'pnr' => 'ABC123']);
        $scope = $this->app->get(BranchScopeResolver::class)->resolve($this->app->get(UserRepository::class)->findById($this->userIds[0]));

        return $this->app->get(FlightRepository::class)->findById($id, $scope);
    }

    /** @return array{name:string,tmp_name:string,error:int,size:int} */
    private function file(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);
        $this->tmp[] = $path;

        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
    }

    private function pdf(string $marker = ''): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 3 3]>>endobj\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF{$marker}";
    }

    private function reloadMedical(int $id, User $actor): \App\Models\MedicalRecord
    {
        return $this->app->get(MedicalRepository::class)->findById($id, $this->app->get(BranchScopeResolver::class)->resolve($actor));
    }

    private function svc(): AttachmentService
    {
        return $this->app->get(AttachmentService::class);
    }

    // ---- medical certificate ---------------------------------------------------------------------------

    public function test_a_certificate_becomes_a_candidate_document_and_the_medical_points_at_it(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $m = $this->medical($c, $actor);

        $doc = $this->svc()->medicalCertificate($m, $this->file('fit-report.pdf', $this->pdf()), $actor);

        $type = $this->db->selectValue('SELECT t.key_name FROM candidate_documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.id = ?', [$doc->id]);
        self::assertSame('medical_certificate', $type);
        self::assertSame($c->id, (int) $this->db->selectValue('SELECT candidate_id FROM candidate_documents WHERE id = ?', [$doc->id]));

        $fresh = $this->reloadMedical($m->id, $actor);
        self::assertSame($doc->publicId, $fresh->certificatePublicId);
        self::assertSame('fit-report.pdf', $fresh->certificateName);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE action = 'certificate_attached' AND record_id = ?", [$m->id]));
    }

    public function test_replacing_keeps_the_old_file_in_documents_and_moves_the_pointer(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $m = $this->medical($c, $actor);

        $first = $this->svc()->medicalCertificate($m, $this->file('old.pdf', $this->pdf(' one')), $actor);
        $second = $this->svc()->medicalCertificate($m, $this->file('new.pdf', $this->pdf(' two')), $actor);

        self::assertSame($second->publicId, $this->reloadMedical($m->id, $actor)->certificatePublicId);
        self::assertSame(2, (int) $this->db->selectValue('SELECT COUNT(*) FROM candidate_documents WHERE candidate_id = ? AND document_type_id = (SELECT id FROM document_types WHERE key_name = ?)', [$c->id, 'medical_certificate']));
        self::assertNotSame($first->publicId, $second->publicId);
    }

    public function test_a_certificate_needs_an_attended_medical_and_a_valid_file(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $scheduled = $this->medical($c, $actor, attended: false);

        try {
            $this->svc()->medicalCertificate($scheduled, $this->file('a.pdf', $this->pdf()), $actor);
            self::fail('attached before the medical happened');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('attended', $e->getMessage());
        }

        $attended = $this->app->get(MedicalService::class)->markAttended($scheduled, gmdate('Y-m-d'), $actor);
        try {
            $this->svc()->medicalCertificate($attended, $this->file('evil.pdf', "MZ\x90\x00 not a pdf"), $actor);
            self::fail('accepted an executable as a certificate');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        self::assertNull($this->reloadMedical($attended->id, $actor)->certificatePublicId, 'a rejected file never becomes the certificate');
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM candidate_documents WHERE candidate_id = ?', [$c->id]));
    }

    public function test_only_people_who_can_edit_medicals_and_upload_documents_may_attach(): void
    {
        $manager = $this->actor();
        $c = $this->candidate($manager);
        $m = $this->medical($c, $manager);

        $this->expectException(AuthorizationException::class);
        $this->svc()->medicalCertificate($m, $this->file('a.pdf', $this->pdf()), $this->actor('read_only'));
    }

    // ---- flight ticket ---------------------------------------------------------------------------------

    public function test_a_ticket_is_attached_to_a_booked_flight(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $f = $this->flight($c, 'booked');

        $doc = $this->svc()->flightTicket($f, $this->file('eticket.pdf', $this->pdf()), $actor);

        self::assertSame('flight_ticket', $this->db->selectValue('SELECT t.key_name FROM candidate_documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.id = ?', [$doc->id]));
        $fresh = $this->app->get(FlightRepository::class)->findById($f->id, $this->app->get(BranchScopeResolver::class)->resolve($actor));
        self::assertSame($doc->publicId, $fresh->ticketDocumentPublicId);
        self::assertSame('eticket.pdf', $fresh->ticketDocumentName);
    }

    public function test_a_ticket_cannot_be_attached_to_a_planned_cancelled_or_flown_flight(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);

        foreach (['planned', 'cancelled', 'flown'] as $status) {
            try {
                $this->svc()->flightTicket($this->flight($c, $status), $this->file('t.pdf', $this->pdf()), $actor);
                self::fail("attached a ticket to a {$status} flight");
            } catch (DomainRuleException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM candidate_documents WHERE candidate_id = ?', [$c->id]));
    }

    // ---- through the screens ------------------------------------------------------------------------------

    public function test_the_upload_route_and_the_candidate_page_link_work_end_to_end(): void
    {
        $actor = $this->actor();
        $c = $this->candidate($actor);
        $m = $this->medical($c, $actor);

        $store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $store);
        $sid = bin2hex(random_bytes(32));
        $token = bin2hex(random_bytes(32));
        $store->sessions[$sid] = ['data' => ['_auth_user_id' => $actor->id, '_auth_at' => time(), '_token' => $token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $gate = new Gate($this->app, $this->app->get(PermissionService::class), $auth);
        foreach ([\App\Models\Lead::class => \App\Policies\LeadPolicy::class, \App\Models\Candidate::class => \App\Policies\CandidatePolicy::class, \App\Models\MedicalRecord::class => \App\Policies\MedicalPolicy::class, \App\Models\FlightBooking::class => \App\Policies\FlightPolicy::class] as $model => $policy) {
            $gate->policy($model, $policy);
        }
        $this->app->instance(Gate::class, $gate);
        $router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($router);
        $router->finalizeNames();
        $this->app->instance(Router::class, $router);

        $file = $this->file('scan.pdf', $this->pdf());
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => "/medical/{$m->publicId}/certificate", 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost'];
        $res = $router->dispatch(new Request([], ['_token' => $token], ['crm_session' => $sid], ['file' => $file], $server, ''));

        self::assertSame(302, $res->getStatus());
        self::assertSame('/candidates/' . $c->publicId . '#medical', $res->getHeader('Location'));
        $fresh = $this->reloadMedical($m->id, $actor);
        self::assertSame('scan.pdf', $fresh->certificateName);

        $page = $router->dispatch(new Request([], [], ['crm_session' => $sid], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/candidates/' . $c->publicId, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'], ''))->getBody();
        self::assertStringContainsString('/documents/' . $fresh->certificatePublicId . '/download', $page);
        self::assertStringContainsString('Replace certificate', $page);
        self::assertStringContainsString('enctype="multipart/form-data"', $page);

        // no file chosen → a friendly refusal, nothing attached
        $res = $router->dispatch(new Request([], ['_token' => $token], ['crm_session' => $sid], [], $server, ''));
        self::assertSame(302, $res->getStatus());
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM candidate_documents WHERE candidate_id = ?', [$c->id]));
    }
}
