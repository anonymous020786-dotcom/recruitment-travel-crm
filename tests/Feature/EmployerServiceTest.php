<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\EmployerContactRepository;
use App\Repositories\EmployerRepository;
use App\Services\EmployerService;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

final class EmployerServiceTest extends DbTestCase
{
    private EmployerService $service;
    private EmployerRepository $repo;
    private EmployerContactRepository $contacts;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->db->exists('SELECT 1 FROM role_permissions LIMIT 1')) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(EmployerService::class);
        $this->repo = $this->app->get(EmployerRepository::class);
        $this->contacts = $this->app->get(EmployerContactRepository::class);
        $this->branchA = $this->branch('EX-A');
        $this->branchB = $this->branch('EX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'EX-%')";
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'employers'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'EX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope LIKE 'employer:%'");
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'ex_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function data(array $o = []): array
    {
        return array_merge(['company_name' => 'Al Noor Trading', 'country' => 'AE', 'status' => 'active'], $o);
    }

    public function test_create_assigns_number_branch_and_audits(): void
    {
        $actor = $this->actor();
        $e = $this->service->create($this->data(), $actor, $this->branchA);

        self::assertMatchesRegularExpression('/^EMP-\d{4}-\d{6}$/', $e->employerNumber);
        self::assertSame($this->branchA, $e->branchId);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='employers' AND action='created' AND record_id = ?", [$e->id]));
    }

    public function test_create_rejects_out_of_scope_branch(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->data(), $this->actor(), $this->branchB);
    }

    public function test_create_denies_role_without_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->create($this->data(), $this->actor('read_only'), $this->branchA);
    }

    public function test_update_changes_fields(): void
    {
        $actor = $this->actor();
        $e = $this->service->create($this->data(), $actor, $this->branchA);

        $u = $this->service->update($e, ['company_name' => 'Al Noor Group', 'status' => 'suspended'], $actor);

        self::assertSame('Al Noor Group', $u->companyName);
        self::assertSame('suspended', $u->status);
    }

    public function test_update_denies_cross_branch_manager(): void
    {
        $e = $this->service->create($this->data(), $this->actor(), $this->branchA);

        $this->expectException(AuthorizationException::class);
        $this->service->update($e, ['company_name' => 'X'], $this->actor('manager', $this->branchB));
    }

    public function test_out_of_scope_user_cannot_find_employer(): void
    {
        $e = $this->service->create($this->data(), $this->actor(), $this->branchA);
        $outsider = $this->actor('manager', $this->branchB);
        $scope = $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($outsider);

        self::assertNull($this->repo->findByPublicId($e->publicId, $scope));
    }

    public function test_delete_soft_deletes_and_hides(): void
    {
        $actor = $this->actor();
        $e = $this->service->create($this->data(), $actor, $this->branchA);
        $scope = $this->app->get(\App\Auth\BranchScopeResolver::class)->resolve($actor);

        $this->service->delete($e, $actor);

        self::assertNull($this->repo->findById($e->id, $scope));
        self::assertNotNull($this->db->selectValue('SELECT deleted_at FROM employers WHERE id = ?', [$e->id]));
    }

    public function test_add_contact_and_single_primary_invariant(): void
    {
        $actor = $this->actor();
        $e = $this->service->create($this->data(), $actor, $this->branchA);

        $first = $this->service->addContact($e, ['name' => 'Sara', 'designation' => null, 'email' => null, 'phone' => null, 'is_primary' => true], $actor);
        $this->service->addContact($e, ['name' => 'Omar', 'designation' => null, 'email' => null, 'phone' => null, 'is_primary' => true], $actor);

        self::assertFalse($this->contacts->findInEmployer($first->id, $e->id)->isPrimary);
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM employer_contacts WHERE employer_id = ? AND is_primary = 1', [$e->id]));
    }

    public function test_update_and_remove_contact(): void
    {
        $actor = $this->actor();
        $e = $this->service->create($this->data(), $actor, $this->branchA);
        $c = $this->service->addContact($e, ['name' => 'Sara', 'designation' => null, 'email' => null, 'phone' => null, 'is_primary' => false], $actor);

        $u = $this->service->updateContact($e, $c->id, ['name' => 'Sara K', 'designation' => 'HR', 'email' => null, 'phone' => null, 'is_primary' => false], $actor);
        self::assertSame('Sara K', $u->name);

        $this->service->removeContact($e, $c->id, $actor);
        self::assertNull($this->contacts->findInEmployer($c->id, $e->id));
    }

    public function test_contact_ops_reject_unknown_id_and_deny_without_permission(): void
    {
        $actor = $this->actor();
        $e = $this->service->create($this->data(), $actor, $this->branchA);

        try {
            $this->service->removeContact($e, 999999, $actor);
            self::fail('expected DomainRuleException');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $this->expectException(AuthorizationException::class);
        $this->service->addContact($e, ['name' => 'X', 'designation' => null, 'email' => null, 'phone' => null, 'is_primary' => false], $this->actor('counselor'));
    }
}
