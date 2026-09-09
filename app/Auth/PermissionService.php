<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Repositories\PermissionRepository;

/**
 * Resolves whether a user holds a permission.
 *
 * Precedence (highest first):
 *   1. per-user override  effect = 'deny'   → denied
 *   2. per-user override  effect = 'allow'  → allowed
 *   3. role grant (role_permissions)        → allowed
 *   4. otherwise                            → denied
 *
 * `super_admin` short-circuits to allowed for everything.
 *
 * Results are memoised per user id for the lifetime of the request.
 */
final class PermissionService
{
    /** @var array<int,array{role:list<string>,overrides:array<string,string>}> */
    private array $cache = [];

    public function __construct(private readonly PermissionRepository $repo)
    {
    }

    public function userCan(User $user, string $permission): bool
    {
        if ($user->roleName === 'super_admin') {
            return true;
        }

        $data = $this->load($user);

        if (($data['overrides'][$permission] ?? null) === 'deny') {
            return false;
        }
        if (($data['overrides'][$permission] ?? null) === 'allow') {
            return true;
        }

        return in_array($permission, $data['role'], true);
    }

    public function userCannot(User $user, string $permission): bool
    {
        return !$this->userCan($user, $permission);
    }

    /** @param list<string> $permissions */
    public function userCanAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->userCan($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> effective permission names for the user */
    public function effectivePermissions(User $user): array
    {
        if ($user->roleName === 'super_admin') {
            return $this->repo->allNames();
        }

        $data = $this->load($user);
        $set = [];
        foreach ($data['role'] as $name) {
            $set[$name] = true;
        }
        foreach ($data['overrides'] as $name => $effect) {
            if ($effect === 'allow') {
                $set[$name] = true;
            } else {
                unset($set[$name]);
            }
        }

        return array_keys($set);
    }

    public function forget(int $userId): void
    {
        unset($this->cache[$userId]);
    }

    /** @return array{role:list<string>,overrides:array<string,string>} */
    private function load(User $user): array
    {
        return $this->cache[$user->id] ??= [
            'role'      => $this->repo->namesForRole($user->roleId),
            'overrides' => $this->repo->overridesForUser($user->id),
        ];
    }
}
