<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\PermissionMatrix;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\RoleAdminRepository;
use App\Support\Application;
use App\Support\Db;

/**
 * Editing which permissions each role holds. The rules that stop the matrix being used to escalate privilege or to
 * lock the organisation out live here:
 *
 *  - only a super admin edits the matrix at all;
 *  - the super_admin role is implicit-everything and is not editable;
 *  - `roles.manage` belongs to super_admin alone — it can never be granted to another role;
 *  - only permissions that exist in the catalogue can be granted;
 *  - a role that can do anything in a module must be able to see it (`<module>.view`, when the module has one) — a
 *    write permission with no way to open the screen is a configuration mistake, not a feature;
 *  - the admin role keeps `dashboard.view`, `users.view` and `users.manage`, so an administrator can always get back in to fix things.
 *
 * Permissions are read from the database on every request, so a change applies at the person's next click — no one needs
 * to sign out. Every change is audited with exactly what was added and removed.
 */
final class RoleAdminService
{
    private const SUPER = 'super_admin';
    private const SUPER_ONLY = ['roles.manage'];
    private const ADMIN_FLOOR = ['dashboard.view', 'users.view', 'users.manage'];

    public function __construct(
        private readonly RoleAdminRepository $roles,
        private readonly AuditService $audit,
        private readonly Application $app,
        private readonly Db $db,
    ) {
    }

    /**
     * @param list<string> $names the complete set of permissions the role should hold
     * @return array{added:list<string>,removed:list<string>}
     * @throws AuthorizationException|DomainRuleException|ValidationException
     */
    public function update(string $roleName, array $names, User $actor): array
    {
        $role = $this->editable($roleName, $actor);
        $names = $this->validated($role['name'], $names);

        return $this->apply($role, $names, $actor, 'role_permissions_changed');
    }

    /**
     * Puts the role back to the shipped default (config/permissions.php).
     *
     * @return array{added:list<string>,removed:list<string>}
     * @throws AuthorizationException|DomainRuleException
     */
    public function reset(string $roleName, User $actor): array
    {
        $role = $this->editable($roleName, $actor);

        return $this->apply($role, $this->defaultsFor($role['name']), $actor, 'role_permissions_reset');
    }

    /** @return list<string> the shipped default permission names for the role */
    public function defaultsFor(string $roleName): array
    {
        $all = [];
        $byModule = [];
        foreach ($this->roles->catalogue() as $module => $permissions) {
            foreach ($permissions as $p) {
                $all[] = $p['name'];
                $byModule[$module][] = $p['name'];
            }
        }
        $tokens = (array) $this->app->config()->get('permissions.matrix.' . $roleName, []);
        $names = array_values(array_diff(PermissionMatrix::resolve($tokens, $all, $byModule), self::SUPER_ONLY));
        sort($names);

        return $names;
    }

    /** @return array{id:int,name:string,label:string,description:?string,is_system:bool,users:int,permissions:int} */
    private function editable(string $roleName, User $actor): array
    {
        if (!$actor->isSuperAdmin()) {
            throw AuthorizationException::forPermission('roles.manage');
        }
        $role = $this->roles->findByName($roleName);
        if ($role === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Role not found.', [], 404);
        }
        if ($role['name'] === self::SUPER) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The super admin role always has every permission and cannot be edited.', [], 422);
        }

        return $role;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     * @throws ValidationException|DomainRuleException
     */
    private function validated(string $roleName, array $names): array
    {
        $catalogue = $this->roles->catalogue();
        $known = [];
        foreach ($catalogue as $permissions) {
            foreach ($permissions as $p) {
                $known[$p['name']] = true;
            }
        }

        $set = [];
        foreach ($names as $name) {
            if (!is_string($name) || !isset($known[$name])) {
                throw new ValidationException(['permissions' => ['One of the selected permissions does not exist.']]);
            }
            $set[$name] = true;
        }
        foreach (self::SUPER_ONLY as $name) {
            if (isset($set[$name])) {
                throw new DomainRuleException('ROLE_SUPER_ONLY', '“' . $name . '” belongs to the super admin alone and cannot be granted to a role.', [], 422);
            }
        }

        if ($roleName === 'admin') {
            $lost = array_values(array_filter(self::ADMIN_FLOOR, static fn (string $n): bool => !isset($set[$n])));
            if ($lost !== []) {
                throw new DomainRuleException('ROLE_ADMIN_FLOOR', 'The Admin role must keep ' . implode(', ', $lost) . ' so administrators are never locked out.', [], 422);
            }
        }

        $missing = [];
        foreach ($catalogue as $module => $permissions) {
            $modulePerms = array_column($permissions, 'name');
            $granted = array_filter($modulePerms, static fn (string $n): bool => isset($set[$n]));
            if ($granted !== [] && in_array($module . '.view', $modulePerms, true) && !isset($set[$module . '.view'])) {
                $missing[] = $module . '.view';
            }
        }
        if ($missing !== []) {
            throw new DomainRuleException('ROLE_NEEDS_VIEW', 'A role that can act in a module must also be able to view it. Add: ' . implode(', ', $missing) . '.', [], 422);
        }

        $out = array_keys($set);
        sort($out);

        return $out;
    }

    /**
     * @param array{id:int,name:string} $role
     * @param list<string> $names
     * @return array{added:list<string>,removed:list<string>}
     */
    private function apply(array $role, array $names, User $actor, string $action): array
    {
        return $this->db->transaction(function () use ($role, $names, $actor, $action): array {
            $diff = $this->roles->sync($role['id'], $names);
            if ($diff['added'] !== [] || $diff['removed'] !== []) {
                $this->audit->log($action, 'roles', 'role', $role['id'], ['role' => $role['name']], ['role' => $role['name'], 'added' => $diff['added'], 'removed' => $diff['removed']], null, $actor);
            }

            return $diff;
        });
    }
}
