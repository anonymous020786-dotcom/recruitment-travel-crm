<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\CandidateDocumentRepository;
use App\Repositories\ChecklistRepository;
use App\Repositories\DocumentTypeRepository;
use App\Services\CandidateService;
use App\Services\DocumentService;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class DocumentServiceTest extends DbTestCase
{
    private LeadService $leadService;
    private DocumentService $service;
    private CandidateDocumentRepository $documents;
    private DocumentTypeRepository $types;
    private ChecklistRepository $checklist;
    private int $branchA;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->db->exists('SELECT 1 FROM role_permissions LIMIT 1')
            || (int) $this->db->selectValue('SELECT COUNT(*) FROM document_types') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->leadService = $this->app->get(LeadService::class);
        $this->service = $this->app->get(DocumentService::class);
        $this->documents = $this->app->get(CandidateDocumentRepository::class);
        $this->types = $this->app->get(DocumentTypeRepository::class);
        $this->checklist = $this->app->get(ChecklistRepository::class);
        $this->branchA = $this->branch('DX-A');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'DX-%')";
        $docRows = $this->db->select(
            "SELECT storage_path FROM candidate_documents WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})",
        );
        foreach ($docRows as $d) {
            @unlink($this->app->basePath((string) $d['storage_path']));
        }
        $this->db->affectingStatement("DELETE FROM candidate_documents WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('leads', 'candidates')");
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
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'DX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%' OR scope LIKE 'candidate:%'");
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => "Branch {$code}",
            'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}",
            'email' => 'dx_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function candidate(User $actor): Candidate
    {
        $lead = $this->leadService->create([
            'name' => 'Convertee', 'phone' => '98' . random_int(10000000, 99999999), 'priority' => 'medium',
        ], $actor, $this->branchA, confirmedNotDuplicate: true);

        $candidate = $this->leadService->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $candidate->personId;

        return $candidate;
    }

    /** @return array{name:string,tmp_name:string,error:int,size:int} */
    private function fakeFile(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'docupload');
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
    }

    private function validPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 3 3]>>endobj\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF";
    }

    private function validPng(): string
    {
        // 1x1 transparent PNG, a well-known minimal fixture.
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }

    public function test_upload_pdf_persists_and_audits(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');

        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, '2030-01-01', $actor);

        self::assertSame('application/pdf', $doc->mimeType);
        self::assertSame('pdf', $doc->extension);
        self::assertSame('uploaded', $doc->status);
        self::assertTrue(is_file($this->app->basePath($doc->storagePath)));
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='candidates' AND action='document_uploaded' AND record_id = ?",
            [$candidate->id],
        ));
    }

    public function test_upload_png_persists(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('photo');

        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('photo.png', $this->validPng()), null, null, $actor);

        self::assertSame('image/png', $doc->mimeType);
        self::assertSame('png', $doc->extension);
    }

    public function test_upload_rejects_disallowed_mime(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');

        $this->expectException(ValidationException::class);
        $this->service->upload($candidate, $type->id, $this->fakeFile('notes.txt', 'just plain text'), null, null, $actor);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        // the limit is checked against the real size on disk (a reported size can lie), so make the file genuinely big
        $file = $this->fakeFile('passport.pdf', $this->validPdf() . str_repeat(' ', ($type->maxSizeKb + 1) * 1024));

        $this->expectException(ValidationException::class);
        $this->service->upload($candidate, $type->id, $file, null, null, $actor);
    }

    public function test_upload_rejects_pdf_with_embedded_javascript(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $malicious = $this->validPdf() . "\n/JavaScript (app.alert('x'))";

        $this->expectException(ValidationException::class);
        $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $malicious), null, null, $actor);
    }

    public function test_upload_rejects_upload_error(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');

        $this->expectException(ValidationException::class);
        $this->service->upload($candidate, $type->id, ['name' => 'x.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0], null, null, $actor);
    }

    public function test_upload_rejects_invalid_document_type(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $this->expectException(ValidationException::class);
        $this->service->upload($candidate, 999999, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);
    }

    public function test_upload_denies_agent_without_permission(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $readOnly = $this->actor('read_only');
        $type = $this->types->findByKey('passport');

        $this->expectException(AuthorizationException::class);
        $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $readOnly);
    }

    public function test_upload_denies_cross_branch_manager(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $otherBranch = $this->branch('DX-B');
        $outsider = $this->actor('manager', $otherBranch);
        $type = $this->types->findByKey('passport');

        $this->expectException(AuthorizationException::class);
        $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $outsider);
    }

    public function test_delete_removes_row_and_file(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);
        $absolute = $this->app->basePath($doc->storagePath);
        self::assertTrue(is_file($absolute));

        $this->service->delete($candidate, $doc->id, $actor);

        self::assertFalse($this->db->exists('SELECT 1 FROM candidate_documents WHERE id = ?', [$doc->id]));
        self::assertFalse(is_file($absolute));
    }

    public function test_delete_rejects_unknown_id(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $this->expectException(DomainRuleException::class);
        $this->service->delete($candidate, 999999, $actor);
    }

    public function test_delete_denies_agent_without_permission(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);
        $readOnly = $this->actor('read_only');

        $this->expectException(AuthorizationException::class);
        $this->service->delete($candidate, $doc->id, $readOnly);
    }

    public function test_log_access_writes_a_row(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);

        $this->service->logAccess($doc, $actor, 'download', "\x7f\x00\x00\x01");

        self::assertTrue($this->db->exists(
            "SELECT 1 FROM document_access_log WHERE document_id = ? AND user_id = ? AND action = 'download'",
            [$doc->id, $actor->id],
        ));
    }

    public function test_find_by_public_id_respects_branch_scope(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);

        $otherBranch = $this->branch('DX-C');
        $outsider = $this->actor('manager', $otherBranch);
        $scope = $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($outsider);

        self::assertNull($this->documents->findByPublicId($doc->publicId, $scope));
    }

    public function test_start_review_transitions_uploaded_to_under_review(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);

        $updated = $this->service->startReview($candidate, $doc->id, $actor, $doc->recordVersion);

        self::assertSame('under_review', $updated->status);
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='candidates' AND action='document_review_started' AND record_id = ?",
            [$candidate->id],
        ));
    }

    public function test_verify_transitions_uploaded_to_verified_and_records_reviewer(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);

        $updated = $this->service->verify($candidate, $doc->id, $actor, $doc->recordVersion);

        self::assertSame('verified', $updated->status);
        self::assertSame($actor->id, $updated->verifiedBy);
        self::assertNotNull($updated->verifiedAt);
    }

    public function test_verify_via_under_review_also_works(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);
        $reviewing = $this->service->startReview($candidate, $doc->id, $actor, $doc->recordVersion);

        $updated = $this->service->verify($candidate, $doc->id, $actor, $reviewing->recordVersion);

        self::assertSame('verified', $updated->status);
    }

    public function test_reject_requires_a_reason(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);

        $this->expectException(ValidationException::class);
        $this->service->reject($candidate, $doc->id, '   ', $actor, $doc->recordVersion);
    }

    public function test_reject_transitions_and_stores_reason(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);

        $updated = $this->service->reject($candidate, $doc->id, 'Photo is blurry.', $actor, $doc->recordVersion);

        self::assertSame('rejected', $updated->status);
        self::assertSame('Photo is blurry.', $updated->rejectionReason);
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='candidates' AND action='document_rejected' AND record_id = ?",
            [$candidate->id],
        ));
    }

    public function test_verify_rejects_an_already_verified_document(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);
        $verified = $this->service->verify($candidate, $doc->id, $actor, $doc->recordVersion);

        $this->expectException(DomainRuleException::class);
        $this->service->verify($candidate, $doc->id, $actor, $verified->recordVersion);
    }

    public function test_verify_rejects_stale_version(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);

        $this->expectException(\App\Exceptions\StaleRecordException::class);
        $this->service->verify($candidate, $doc->id, $actor, $doc->recordVersion + 5);
    }

    public function test_verify_denies_agent_without_permission(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);
        $counselor = $this->actor('counselor'); // can upload/view/download but not verify/reject

        $this->expectException(AuthorizationException::class);
        $this->service->verify($candidate, $doc->id, $counselor, $doc->recordVersion);
    }

    public function test_reject_denies_agent_without_permission(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);
        $counselor = $this->actor('counselor');

        $this->expectException(AuthorizationException::class);
        $this->service->reject($candidate, $doc->id, 'Not clear.', $counselor, $doc->recordVersion);
    }

    public function test_expire_due_marks_only_past_verified_documents(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $expired = $this->service->upload($candidate, $type->id, $this->fakeFile('a.pdf', $this->validPdf()), null, '2020-01-01', $actor);
        $future = $this->service->upload($candidate, $type->id, $this->fakeFile('b.pdf', $this->validPdf()), null, '2099-01-01', $actor);
        $expired = $this->service->verify($candidate, $expired->id, $actor, $expired->recordVersion);
        $future = $this->service->verify($candidate, $future->id, $actor, $future->recordVersion);

        $count = $this->service->expireDue('2026-01-01');

        self::assertSame(1, $count);
        self::assertSame('expired', $this->documents->findInCandidate($expired->id, $candidate->id)->status);
        self::assertSame('verified', $this->documents->findInCandidate($future->id, $candidate->id)->status);
    }

    public function test_expire_due_is_idempotent(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('a.pdf', $this->validPdf()), null, '2020-01-01', $actor);
        $doc = $this->service->verify($candidate, $doc->id, $actor, $doc->recordVersion);

        $first = $this->service->expireDue('2026-01-01');
        $second = $this->service->expireDue('2026-01-01');

        self::assertSame(1, $first);
        self::assertSame(0, $second);
    }

    public function test_checklist_seeds_from_required_default_types(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $items = $this->checklist->forCandidate($candidate->id);

        $requiredDefaultCount = (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM document_types WHERE is_required_default = 1 AND is_active = 1',
        );
        self::assertSame($requiredDefaultCount, count($items));
        foreach ($items as $item) {
            self::assertTrue($item->isRequired);
            self::assertFalse($item->isSatisfied());
        }
    }

    public function test_checklist_seeding_is_idempotent(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);

        $first = count($this->checklist->forCandidate($candidate->id));
        $second = count($this->checklist->forCandidate($candidate->id));

        self::assertSame($first, $second);
    }

    public function test_verifying_a_document_satisfies_its_checklist_item(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        self::assertTrue($type->isRequiredDefault, 'test assumes passport is a default-required type');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, null, $actor);

        $this->service->verify($candidate, $doc->id, $actor, $doc->recordVersion);

        $items = $this->checklist->forCandidate($candidate->id);
        $passportItem = current(array_filter($items, static fn ($i) => $i->documentTypeId === $type->id));
        self::assertNotFalse($passportItem);
        self::assertTrue($passportItem->isSatisfied());
        self::assertSame($doc->id, $passportItem->satisfiedDocumentId);
    }

    public function test_expiring_a_document_clears_its_checklist_satisfaction(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $doc = $this->service->upload($candidate, $type->id, $this->fakeFile('passport.pdf', $this->validPdf()), null, '2020-01-01', $actor);
        $this->service->verify($candidate, $doc->id, $actor, $doc->recordVersion);

        $this->service->expireDue('2026-01-01');

        $items = $this->checklist->forCandidate($candidate->id);
        $passportItem = current(array_filter($items, static fn ($i) => $i->documentTypeId === $type->id));
        self::assertNotFalse($passportItem);
        self::assertFalse($passportItem->isSatisfied());
        self::assertNull($passportItem->satisfiedDocumentId);
    }

    public function test_toggle_checklist_requirement_waives_and_restores(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $this->checklist->forCandidate($candidate->id); // seed

        $this->service->toggleChecklistRequirement($candidate, $type->id, false, $actor);
        $items = $this->checklist->forCandidate($candidate->id);
        $passportItem = current(array_filter($items, static fn ($i) => $i->documentTypeId === $type->id));
        self::assertFalse($passportItem->isRequired);
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM activity_logs WHERE module='candidates' AND action='checklist_updated' AND record_id = ?",
            [$candidate->id],
        ));

        $this->service->toggleChecklistRequirement($candidate, $type->id, true, $actor);
        $items = $this->checklist->forCandidate($candidate->id);
        $passportItem = current(array_filter($items, static fn ($i) => $i->documentTypeId === $type->id));
        self::assertTrue($passportItem->isRequired);
    }

    public function test_toggle_checklist_requirement_denies_agent_without_permission(): void
    {
        $actor = $this->actor('manager');
        $candidate = $this->candidate($actor);
        $type = $this->types->findByKey('passport');
        $counselor = $this->actor('counselor');

        $this->expectException(AuthorizationException::class);
        $this->service->toggleChecklistRequirement($candidate, $type->id, false, $counselor);
    }
}
