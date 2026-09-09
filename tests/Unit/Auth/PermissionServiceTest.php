<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\PermissionService;
use App\Models\User;
use App\Repositories\PermissionRepository;
use PHPUnit\Framework\TestCase;

final class PermissionServiceTest extends TestCase
{
    private function user(string $role = 'counselor', int $id = 10, int $roleId = 3): User
    {
        return new User($id, 'PID', 'X', 'x@x', $roleId, $role, 1, false, true, null, false);
    }

    private function service(array $roleGrants, array $overrides = []): PermissionService
    {
        $repo = new class ($roleGrants, $overrides) extends PermissionRepository {
            public function __construct(private array $grants, private array $ovr)
            {
            }
            public function namesForRole(int $roleId): array
            {
                return $this->grants;
            }
            public function overridesForUser(int $userId): array
            {
                return $this->ovr;
            }
            public function allNames(): array
            {
                return ['a.x', 'a.y', 'b.z'];
            }
        };

        return new PermissionService($repo);
    }

    public function test_role_grant_allows(): void
    {
        $svc = $this->service(['leads.view', 'leads.create']);
        self::assertTrue($svc->userCan($this->user(), 'leads.view'));
        self::assertFalse($svc->userCan($this->user(), 'leads.delete'));
    }

    public function test_deny_override_beats_role_grant(): void
    {
        $svc = $this->service(['payments.view'], ['payments.view' => 'deny']);
        self::assertFalse($svc->userCan($this->user(), 'payments.view'));
    }

    public function test_allow_override_grants_without_role(): void
    {
        $svc = $this->service([], ['reports.finance.view' => 'allow']);
        self::assertTrue($svc->userCan($this->user(), 'reports.finance.view'));
    }

    public function test_super_admin_bypasses_everything(): void
    {
        $svc = $this->service([]); // no grants at all
        self::assertTrue($svc->userCan($this->user('super_admin', 1, 1), 'anything.at.all'));
    }

    public function test_can_any(): void
    {
        $svc = $this->service(['b.z']);
        self::assertTrue($svc->userCanAny($this->user(), ['a.x', 'b.z']));
        self::assertFalse($svc->userCanAny($this->user(), ['a.x', 'a.y']));
    }

    public function test_effective_permissions_merges_overrides(): void
    {
        $svc = $this->service(['a.x', 'a.y'], ['a.y' => 'deny', 'b.z' => 'allow']);
        $eff = $svc->effectivePermissions($this->user());
        sort($eff);
        self::assertSame(['a.x', 'b.z'], $eff);
    }

    public function test_results_are_memoised_per_user(): void
    {
        $repo = new class extends PermissionRepository {
            public int $calls = 0;
            public function __construct()
            {
            }
            public function namesForRole(int $roleId): array
            {
                $this->calls++;
                return ['x.y'];
            }
            public function overridesForUser(int $userId): array
            {
                return [];
            }
        };
        $svc = new PermissionService($repo);
        $u = $this->user();
        $svc->userCan($u, 'x.y');
        $svc->userCan($u, 'x.z');
        self::assertSame(1, $repo->calls);
    }
}
