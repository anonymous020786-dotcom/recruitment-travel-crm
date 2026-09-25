<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Integrations\Credentials;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\CandidateDocumentRepository;
use App\Repositories\UserRepository;
use App\Services\DocumentService;
use App\Services\LeadService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Storage\CostEstimator;
use App\Storage\DocumentMigrator;
use App\Storage\ObjectStorage;
use App\Storage\OffsiteBackup;
use App\Storage\Transport;
use App\Support\Hash;
use App\Support\Logger;
use App\Support\Ulid;
use Tests\Support\DbTestCase;
use Tests\Support\FakeTransport;

/** Documents in an S3/R2 bucket: upload placement and its fail-safe, delivery (signed link vs proxy), deletion, migration, cost estimate, admin screens. */
final class ObjectStorageDocumentsTest extends DbTestCase
{
    private FakeTransport $net;
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private string $token = '';
    private int $branch;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];
    /** @var list<string> */
    private array $tmp = [];
    /** @var list<array<string,mixed>> */
    private array $credSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM document_types') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->credSnapshot = $this->db->select('SELECT * FROM integration_credentials');
        $this->db->affectingStatement('DELETE FROM integration_credentials');
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'OS branch', 'code' => 'OSX-' . bin2hex(random_bytes(2))]);

        // every S3 call in these tests goes to a recording fake; ObjectStorage is bound before anything resolves DocumentService
        $this->net = new FakeTransport();
        $this->app->instance(Transport::class, $this->net);
        $this->app->instance(ObjectStorage::class, new ObjectStorage($this->app->get(Credentials::class), $this->net, $this->app->get(Logger::class)));

        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'OSX-%')";
        foreach ($this->db->select("SELECT storage_path FROM candidate_documents WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})") as $d) {
            @unlink($this->app->basePath((string) $d['storage_path']));
        }
        $this->db->affectingStatement("DELETE FROM candidate_documents WHERE candidate_id IN (SELECT id FROM candidates WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('leads', 'candidates', 'storage', 'integrations')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        if ($this->personIds !== []) {
            $ph = implode(',', array_fill(0, count($this->personIds), '?'));
            $this->db->affectingStatement("DELETE FROM persons WHERE id IN ({$ph})", $this->personIds);
        }
        $this->db->affectingStatement('DELETE FROM integration_credentials');
        foreach ($this->credSnapshot as $row) {
            $this->db->insertRow('integration_credentials', $row);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'OSX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'lead:%' OR scope LIKE 'candidate:%'");
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------------

    private function user(string $role): User
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "OS {$role}", 'email' => 'os_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function candidate(User $actor): Candidate
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create(['name' => 'OS Cand', 'phone' => '97' . random_int(10000000, 99999999), 'priority' => 'medium'], $actor, $this->branch, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    private function pdf(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'osdoc') ?: '';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 3 3]>>endobj\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF");
        $this->tmp[] = $path;

        return ['name' => 'passport ravi.pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path)];
    }

    /** Configure R2 and choose it (the super admin's part) — done through the real credentials store. */
    private function useR2(array $storage = []): void
    {
        $admin = $this->user('super_admin');
        $c = $this->app->get(Credentials::class);
        $c->save('r2', ['account_id' => 'acc123', 'access_key' => 'AK', 'secret_key' => 'SECRETSECRETSECRET', 'bucket' => 'docs', 'prefix' => 'crm'], $admin);
        $c->save('storage', ['driver' => 'r2'] + $storage, $admin);
        $this->app->get(ObjectStorage::class)->reset();
    }

    private function upload(User $actor, Candidate $c): \App\Models\CandidateDocument
    {
        $type = $this->app->get(\App\Repositories\DocumentTypeRepository::class)->findByKey('passport');

        return $this->app->get(DocumentService::class)->upload($c, $type->id, $this->pdf(), null, '2030-01-01', $actor);
    }

    // ---- where an upload goes -------------------------------------------------------------------------------------------

    public function test_with_r2_chosen_an_upload_lands_in_the_bucket_and_the_server_copy_is_removed(): void
    {
        $this->useR2();
        $actor = $this->user('manager');

        $doc = $this->upload($actor, $this->candidate($actor));

        $put = $this->net->requests[0];
        self::assertSame('r2', $doc->storageDisk);
        self::assertSame('PUT', $put['method']);
        self::assertStringStartsWith('https://acc123.r2.cloudflarestorage.com/docs/crm/documents/', $put['url']);
        self::assertStringEndsWith('.pdf', $put['url']);
        self::assertSame('application/pdf', $put['headers']['content-type']);
        self::assertSame($doc->sha256, $put['headers']['x-amz-meta-sha256'], 'the content hash travels with the object');
        self::assertSame($doc->sha256, $put['headers']['x-amz-content-sha256'], 'and is what the upload is signed with');
        self::assertFileDoesNotExist($this->app->basePath($doc->storagePath), 'the local copy is gone once the bucket has it');
        self::assertSame('r2', $this->db->selectValue('SELECT storage_disk FROM candidate_documents WHERE id = ?', [$doc->id]));
    }

    public function test_if_the_bucket_is_unreachable_the_document_stays_on_the_server_and_nothing_fails(): void
    {
        $this->useR2();
        $this->net->push(['status' => 0, 'error' => 'timed out']);
        $actor = $this->user('manager');

        $doc = $this->upload($actor, $this->candidate($actor));

        self::assertSame('private', $doc->storageDisk);
        self::assertFileExists($this->app->basePath($doc->storagePath), 'never lose a document because of an outage');
    }

    public function test_without_a_configured_provider_uploads_stay_local_and_make_no_network_calls(): void
    {
        $actor = $this->user('manager');
        $this->app->get(Credentials::class)->save('storage', ['driver' => 'r2'], $this->user('super_admin'));   // chosen, but R2 has no keys

        $doc = $this->upload($actor, $this->candidate($actor));

        self::assertSame('private', $doc->storageDisk);
        self::assertSame([], $this->net->requests);
        self::assertSame('private', $this->app->get(ObjectStorage::class)->activeDisk());
    }

    public function test_deleting_removes_the_object_from_the_bucket_or_the_file_from_the_disk(): void
    {
        $this->useR2();
        $actor = $this->user('manager');
        $c = $this->candidate($actor);
        $remote = $this->upload($actor, $c);
        $this->net->requests = [];

        $this->app->get(DocumentService::class)->delete($c, $remote->id, $actor);

        self::assertSame('DELETE', $this->net->last()['method']);
        self::assertStringContainsString('/docs/crm/documents/', $this->net->last()['url']);

        $this->net->push(['status' => 500]);
        $local = $this->upload($actor, $c);
        self::assertSame('private', $local->storageDisk);
        $this->net->requests = [];
        $this->app->get(DocumentService::class)->delete($c, $local->id, $actor);
        self::assertSame([], $this->net->requests, 'a server-disk document is deleted locally, with no bucket call');
        self::assertFileDoesNotExist($this->app->basePath($local->storagePath));
    }

    // ---- serving ----------------------------------------------------------------------------------------------------------

    private function actAs(User $user): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ['_auth_user_id' => $user->id, '_auth_at' => time(), '_authenticated_at' => time(), '_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    /** @param array<string,mixed> $post */
    private function send(string $method, string $uri, array $post = []): Response
    {
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }

        return $this->router->dispatch(new Request([], $post, ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    private function code(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->send($method, $uri, $post)->getStatus();
        } catch (AuthorizationException) {
            return 403;
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    private function body(Response $r): string
    {
        ob_start();
        $r->send();

        return (string) ob_get_clean();
    }

    public function test_a_download_is_a_redirect_to_a_short_lived_signed_link_and_is_logged_first(): void
    {
        $this->useR2(['link_seconds' => '90']);
        $manager = $this->user('manager');
        $doc = $this->upload($manager, $this->candidate($manager));
        $this->actAs($manager);

        $res = $this->send('GET', "/documents/{$doc->publicId}/download");

        self::assertSame(302, $res->getStatus());
        $loc = (string) $res->getHeader('Location');
        $parts = parse_url($loc);
        parse_str((string) $parts['query'], $q);
        self::assertSame('acc123.r2.cloudflarestorage.com', $parts['host']);
        self::assertSame('90', $q['X-Amz-Expires']);
        self::assertSame('attachment; filename="passport ravi.pdf"', $q['response-content-disposition']);
        self::assertSame('application/pdf', $q['response-content-type']);
        self::assertNotEmpty($q['X-Amz-Signature']);
        self::assertStringNotContainsString('SECRETSECRETSECRET', $loc);
        self::assertStringContainsString('no-store', (string) $res->getHeader('Cache-Control'));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM document_access_log WHERE document_id = ? AND action = 'download'", [$doc->id]));
        self::assertCount(1, $this->net->requests, 'the file itself never passed through this server (only the upload happened)');
    }

    public function test_a_preview_and_the_through_the_server_setting_stream_the_bytes_from_the_bucket(): void
    {
        $this->useR2();
        $manager = $this->user('manager');
        $doc = $this->upload($manager, $this->candidate($manager));
        $this->actAs($manager);
        $src = tempnam(sys_get_temp_dir(), 'osb');
        file_put_contents($src, '%PDF-FROM-BUCKET');
        $this->tmp[] = $src;

        $this->net->push(['status' => 200, 'file' => $src]);
        $preview = $this->send('GET', "/documents/{$doc->publicId}/preview");
        self::assertSame(200, $preview->getStatus());
        self::assertStringStartsWith('inline;', (string) $preview->getHeader('Content-Disposition'));
        self::assertSame('nosniff', $preview->getHeader('X-Content-Type-Options'));
        self::assertSame('%PDF-FROM-BUCKET', $this->body($preview));

        $this->app->get(Credentials::class)->save('storage', ['delivery' => 'proxy'], $this->user('super_admin'));
        $this->net->push(['status' => 200, 'file' => $src]);
        $download = $this->send('GET', "/documents/{$doc->publicId}/download");
        self::assertSame(200, $download->getStatus());
        self::assertStringStartsWith('attachment;', (string) $download->getHeader('Content-Disposition'));
        self::assertSame('%PDF-FROM-BUCKET', $this->body($download));
    }

    public function test_when_the_bucket_cannot_deliver_the_user_gets_a_503_not_a_broken_file(): void
    {
        $this->useR2();
        $manager = $this->user('manager');
        $doc = $this->upload($manager, $this->candidate($manager));
        $this->actAs($manager);
        $this->net->push(['status' => 500]);

        self::assertSame(503, $this->code('GET', "/documents/{$doc->publicId}/preview"));
    }

    public function test_authorisation_is_checked_before_a_signed_link_is_ever_made(): void
    {
        $this->useR2();
        $manager = $this->user('manager');
        $doc = $this->upload($manager, $this->candidate($manager));
        $outsider = $this->user('travel');
        if ($this->app->get(PermissionService::class)->userCan($outsider, 'documents.view')) {
            self::markTestSkipped('the travel role may view documents in this database');
        }
        $before = count($this->net->requests);
        $this->actAs($outsider);

        self::assertSame(403, $this->code('GET', "/documents/{$doc->publicId}/download"));
        self::assertCount($before, $this->net->requests, 'no signed link was made for someone who may not have the file');
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM document_access_log WHERE document_id = ?', [$doc->id]));
    }

    // ---- moving existing documents ------------------------------------------------------------------------------------------

    private function localDoc(User $actor, Candidate $c): \App\Models\CandidateDocument
    {
        $doc = $this->upload($actor, $c);
        self::assertSame('private', $doc->storageDisk);

        return $doc;
    }

    public function test_the_migrator_uploads_verifies_then_switches_the_row_and_removes_the_local_copy(): void
    {
        $actor = $this->user('manager');
        $c = $this->candidate($actor);
        $doc = $this->localDoc($actor, $c);
        $size = (int) filesize($this->app->basePath($doc->storagePath));
        $this->useR2();
        $this->net->requests = [];
        $this->net->push(['status' => 200])->push(['status' => 200, 'headers' => ['Content-Length' => (string) $size]]);

        $r = $this->app->get(DocumentMigrator::class)->moveBatch(10, false, $actor);

        self::assertSame([1, 0, 0], [$r['moved'], $r['failed'], $r['missing']]);
        self::assertSame(['PUT', 'HEAD'], array_column($this->net->requests, 'method'));
        self::assertSame('r2', $this->db->selectValue('SELECT storage_disk FROM candidate_documents WHERE id = ?', [$doc->id]));
        self::assertFileDoesNotExist($this->app->basePath($doc->storagePath));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'documents_moved_to_bucket'"));
    }

    public function test_a_failed_upload_or_a_size_mismatch_leaves_the_document_exactly_where_it_was(): void
    {
        $actor = $this->user('manager');
        $c = $this->candidate($actor);
        $a = $this->localDoc($actor, $c);
        $b = $this->localDoc($actor, $c);
        $this->useR2();
        $this->net->requests = [];
        $this->net->push(['status' => 500])                                                         // first upload fails
            ->push(['status' => 200])->push(['status' => 200, 'headers' => ['Content-Length' => '1']]);   // second uploads but the object is the wrong size

        $r = $this->app->get(DocumentMigrator::class)->moveBatch(10, false, $actor);

        self::assertSame([0, 2], [$r['moved'], $r['failed']]);
        foreach ([$a, $b] as $d) {
            self::assertSame('private', $this->db->selectValue('SELECT storage_disk FROM candidate_documents WHERE id = ?', [$d->id]));
            self::assertFileExists($this->app->basePath($d->storagePath), 'the local copy is only removed after verification');
        }
    }

    public function test_missing_files_dry_runs_and_no_bucket_are_handled(): void
    {
        $actor = $this->user('manager');
        $c = $this->candidate($actor);
        $gone = $this->localDoc($actor, $c);
        $kept = $this->localDoc($actor, $c);
        unlink($this->app->basePath($gone->storagePath));

        $noBucket = $this->app->get(DocumentMigrator::class)->moveBatch(10, false, $actor);
        self::assertArrayHasKey('error', $noBucket);
        self::assertSame([], $this->net->requests);

        $this->useR2();
        $this->net->requests = [];
        $dry = $this->app->get(DocumentMigrator::class)->moveBatch(10, true, $actor);
        self::assertSame([1, 1], [$dry['moved'], $dry['missing']]);
        self::assertSame([], $this->net->requests, 'a dry run touches nothing');
        self::assertSame('private', $this->db->selectValue('SELECT storage_disk FROM candidate_documents WHERE id = ?', [$kept->id]));
        self::assertSame(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM candidate_documents WHERE candidate_id = ? AND storage_disk = 'private'", [$c->id]));
    }

    // ---- cost estimate ---------------------------------------------------------------------------------------------------------

    public function test_the_cost_estimate_is_reproducible_arithmetic_from_the_list_prices(): void
    {
        $pricing = [
            'as_of' => 't', 'currency' => 'USD',
            'providers' => [
                's3' => ['label' => 'S3', 'standard' => 0.025, 'infrequent' => 0.0138, 'archive' => 0.005, 'egress' => 0.1093, 'egress_free_gb' => 100, 'put_per_1k' => 0.005, 'get_per_1k' => 0.0004, 'free_gb' => 0],
                'r2' => ['label' => 'R2', 'standard' => 0.015, 'infrequent' => 0.01, 'archive' => null, 'egress' => 0.0, 'egress_free_gb' => 0, 'put_per_1k' => 0.0045, 'get_per_1k' => 0.00036, 'free_gb' => 10],
            ],
        ];

        $rows = array_column(CostEstimator::compare($pricing, 100.0, 0.10, 0.80, 500, 1000), null, 'key');

        self::assertSame(2.5, $rows['s3-standard']['total'], '100 GB × $0.025; 10 GB of downloads are inside the 100 GB free egress');
        self::assertSame(1.35, $rows['r2-standard']['total'], '(100 − 10 free GB) × $0.015; downloads are free');
        self::assertSame(1.15, $rows['s3-lifecycle']['total'], '20 GB standard + 80 GB split between Standard-IA and Glacier IR');
        self::assertSame(0.95, $rows['r2-lifecycle']['total'], '10 billable standard GB + 80 GB Infrequent Access');
        self::assertSame(0.0, $rows['s3-standard']['saving']);
        self::assertSame(1.35, $rows['s3-lifecycle']['saving']);
        self::assertSame(1.15, $rows['r2-standard']['saving']);

        // heavy downloading is where R2's zero egress pays off
        $heavy = array_column(CostEstimator::compare($pricing, 1000.0, 0.5, 0.5, 0, 0), null, 'key');
        self::assertSame(round(1000 * 0.025 + (500 - 100) * 0.1093, 2), $heavy['s3-standard']['total']);
        self::assertGreaterThan(40.0, $heavy['r2-standard']['saving']);

        // out-of-range inputs are clamped, never negative
        foreach (CostEstimator::compare($pricing, -5.0, 9.0, -3.0, 0, 0) as $r) {
            self::assertGreaterThanOrEqual(0.0, $r['total']);
        }
    }

    // ---- the admin screen ---------------------------------------------------------------------------------------------------------

    public function test_the_storage_screen_and_its_actions_are_for_the_super_admin(): void
    {
        $this->actAs($this->user('admin'));
        self::assertSame(403, $this->code('GET', '/admin/storage'));
        self::assertSame(403, $this->code('POST', '/admin/storage/migrate', ['x' => '1']));

        $this->useR2();
        $this->actAs($this->user('super_admin'));
        $page = $this->send('GET', '/admin/storage', [])->getBody();
        self::assertSame(200, $this->code('GET', '/admin/storage'));
        foreach (['Cloudflare R2', 'Amazon S3', 'Monthly cost estimate', 'Apply cost-saving rules', 'Test connection'] as $needle) {
            self::assertStringContainsString($needle, $page);
        }
        self::assertStringNotContainsString('SECRETSECRETSECRET', $page);
    }

    public function test_test_connection_and_lifecycle_talk_to_the_bucket_and_report_the_outcome(): void
    {
        $this->useR2(['infrequent_after_days' => '60', 'backup_retention_days' => '14']);
        $this->actAs($this->user('super_admin'));

        $this->net->requests = [];
        $this->net->push(['status' => 200]);
        $this->send('POST', '/admin/storage/test', ['provider' => 'r2']);
        self::assertSame('HEAD', $this->net->last()['method']);
        self::assertSame('https://acc123.r2.cloudflarestorage.com/docs', $this->net->last()['url'], 'R2 is path-style: the bucket is the first path segment');

        $this->net->push(['status' => 200]);
        $this->send('POST', '/admin/storage/lifecycle', ['x' => '1']);
        $put = $this->net->last();
        self::assertSame('PUT', $put['method']);
        self::assertStringContainsString('lifecycle=', $put['url']);
        self::assertStringContainsString('<Prefix>crm/documents/</Prefix>', (string) $put['body']);
        self::assertStringContainsString('<Days>60</Days><StorageClass>STANDARD_IA</StorageClass>', (string) $put['body']);
        self::assertStringContainsString('<Prefix>crm/backups/</Prefix>', (string) $put['body']);
        self::assertStringContainsString('<Days>14</Days>', (string) $put['body']);
        self::assertStringNotContainsString('GLACIER_IR', (string) $put['body'], 'R2 has no archive tier');
    }

    public function test_the_storage_setting_is_part_of_the_integrations_catalogue(): void
    {
        $svc = $this->app->get(Credentials::class)->service('storage');

        self::assertSame('storage', $svc['group']);
        self::assertSame(['private', 's3', 'r2'], array_keys($svc['fields']['driver']['options']));
        self::assertNull($this->app->get(CandidateDocumentRepository::class)->findByPublicId('nope', \App\Auth\BranchScope::orgWide()));
    }
    // ---- off-site backups -------------------------------------------------------------------------------------------------------

    /** @return array{0:string,1:array<string,mixed>} a backup dir with a db and a files archive, and the BackupManager-shaped result */
    private function backupDir(): array
    {
        $dir = sys_get_temp_dir() . '/osbk_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/db-20260925-040000.sql.gz", str_repeat('D', 300));
        file_put_contents("{$dir}/files-20260925-040000-inc.tar.gz", str_repeat('F', 120));
        file_put_contents("{$dir}/secrets.env", 'SHOULD NEVER LEAVE');
        $this->tmp[] = "{$dir}/db-20260925-040000.sql.gz";
        $this->tmp[] = "{$dir}/files-20260925-040000-inc.tar.gz";
        $this->tmp[] = "{$dir}/secrets.env";

        return [$dir, ['db' => ['file' => 'db-20260925-040000.sql.gz'], 'files' => ['file' => 'files-20260925-040000-inc.tar.gz']]];
    }

    public function test_each_nights_backup_files_are_copied_to_the_bucket_and_verified(): void
    {
        $this->useR2();
        [$dir, $result] = $this->backupDir();
        $this->net->requests = [];
        $this->net->push(['status' => 200])->push(['status' => 200, 'headers' => ['Content-Length' => '300']])
            ->push(['status' => 200])->push(['status' => 200, 'headers' => ['Content-Length' => '120']])
            ->push(['status' => 200, 'body' => '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>false</IsTruncated></ListBucketResult>']);

        $r = $this->app->get(OffsiteBackup::class)->push($dir, $result);

        self::assertSame(['db-20260925-040000.sql.gz', 'files-20260925-040000-inc.tar.gz'], $r['uploaded']);
        self::assertSame([], $r['failed']);
        $urls = array_column($this->net->requests, 'url');
        self::assertStringEndsWith('/docs/crm/backups/db-20260925-040000.sql.gz', $urls[0]);
        self::assertStringEndsWith('/docs/crm/backups/files-20260925-040000-inc.tar.gz', $urls[2]);
        foreach ($urls as $u) {
            self::assertStringNotContainsString('secrets.env', $u, 'only the files BackupManager produced are ever uploaded');
        }
    }

    public function test_a_failed_or_mismatched_copy_is_reported_but_does_not_throw(): void
    {
        $this->useR2();
        [$dir, $result] = $this->backupDir();
        $this->net->push(['status' => 500])                                                          // db upload fails
            ->push(['status' => 200])->push(['status' => 200, 'headers' => ['Content-Length' => '7']])   // files upload is truncated remotely
            ->push(['status' => 200, 'body' => '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>false</IsTruncated></ListBucketResult>']);

        $r = $this->app->get(OffsiteBackup::class)->push($dir, $result);

        self::assertSame([], $r['uploaded']);
        self::assertSame(['db-20260925-040000.sql.gz', 'files-20260925-040000-inc.tar.gz'], $r['failed']);
    }

    public function test_it_does_nothing_without_a_configured_bucket_and_ignores_unsafe_names(): void
    {
        [$dir, $result] = $this->backupDir();
        $off = $this->app->get(OffsiteBackup::class);

        self::assertFalse($off->enabled());
        self::assertTrue($off->push($dir, $result)['skipped']);
        self::assertSame([], $this->net->requests);

        $this->useR2();
        $this->net->push(['status' => 200, 'body' => '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>false</IsTruncated></ListBucketResult>']);
        $r = $off->push($dir, ['db' => ['file' => '../../.env'], 'files' => ['file' => 'secrets.env']]);
        self::assertSame([], $r['uploaded'] + $r['failed'], 'names that are not backup archives are skipped outright');
    }

    public function test_remote_backups_older_than_the_retention_are_pruned_and_newer_ones_kept(): void
    {
        $this->useR2(['backup_retention_days' => '10']);
        $now = strtotime('2026-09-25T00:00:00Z');
        $off = new OffsiteBackup($this->app->get(ObjectStorage::class), $this->app->get(Credentials::class), $this->app->get(Logger::class), static fn (): int => $now);
        $xml = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>false</IsTruncated>'
            . '<Contents><Key>crm/backups/old.gz</Key><LastModified>2026-09-01T04:00:00.000Z</LastModified><Size>1</Size></Contents>'
            . '<Contents><Key>crm/backups/new.gz</Key><LastModified>2026-09-20T04:00:00.000Z</LastModified><Size>1</Size></Contents></ListBucketResult>';
        $this->net->requests = [];
        $this->net->push(['status' => 200, 'body' => $xml])->push(['status' => 204]);

        $removed = $off->prune($this->app->get(ObjectStorage::class)->client('r2'));

        self::assertSame(1, $removed);
        self::assertSame(['GET', 'DELETE'], array_column($this->net->requests, 'method'));
        self::assertStringEndsWith('/docs/crm/backups/old.gz', $this->net->last()['url']);
    }
}
