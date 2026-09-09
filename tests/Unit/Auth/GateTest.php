<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Models\User;
use App\Repositories\PermissionRepository;
use App\Support\Container;
use PHPUnit\Framework\TestCase;

final class GateTest extends TestCase
{
    private function permissionService(array $grants): PermissionService
    {
        $repo = new class ($grants) extends PermissionRepository {
            public function __construct(private array $grants)
            {
            }
            public function namesForRole(int $roleId): array
            {
                return $this->grants;
            }
            public function overridesForUser(int $userId): array
            {
                return [];
            }
        };

        return new PermissionService($repo);
    }

    private function gate(array $grants, ?User $user): Gate
    {
        $container = new Container();
        $auth = new class extends Auth {
            public ?User $u = null;
            public function __construct()
            {
            }
            public function user(): ?User
            {
                return $this->u;
            }
        };
        $auth->u = $user;

        return new Gate($container, $this->permissionService($grants), $auth, $user);
    }

    private function user(string $role = 'counselor'): User
    {
        return new User(7, 'P', 'N', 'e@e', 3, $role, 1, false, true, null, false);
    }

    public function test_raw_permission_allows_and_denies(): void
    {
        $gate = $this->gate(['leads.view'], $this->user());
        self::assertTrue($gate->allows('leads.view'));
        self::assertTrue($gate->denies('leads.delete'));
    }

    public function test_guest_is_denied(): void
    {
        self::assertFalse($this->gate(['leads.view'], null)->allows('leads.view'));
    }

    public function test_super_admin_allowed_for_anything(): void
    {
        self::assertTrue($this->gate([], $this->user('super_admin'))->allows('whatever.you.want'));
    }

    public function test_authorize_throws_on_denial(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->gate([], $this->user())->authorize('leads.delete');
    }

    public function test_closure_ability(): void
    {
        $gate = $this->gate([], $this->user());
        $gate->define('touch-grass', fn (?User $u) => $u !== null);
        self::assertTrue($gate->allows('touch-grass'));
    }

    public function test_policy_dispatch(): void
    {
        $gate = $this->gate([], $this->user());
        $gate->policy(GateFixtureModel::class, GateFixturePolicy::class);

        self::assertTrue($gate->allows('view', GateFixtureModel::class));
        self::assertFalse($gate->allows('delete', GateFixtureModel::class));
    }
}

class GateFixtureModel
{
}

class GateFixturePolicy
{
    public function view(User $user): bool
    {
        return true;
    }

    public function delete(User $user): bool
    {
        return false;
    }
}
