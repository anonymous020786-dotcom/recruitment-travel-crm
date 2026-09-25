<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\PermissionService;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleAdminRepository;
use App\Repositories\UserAdminRepository;
use App\Support\Db;

/**
 * Per-user permission overrides (Admin → Users → Permissions): allow or deny one permission for one person on top of what their
 * role gives, optionally until a date, with a note. PermissionService already resolves them (deny beats allow beats role); this
 * class is the rule book for changing them:
 *  - only a super admin may change them, and only for someone who is not a super admin (they hold everything — which also
 *    means you can never change your own);
 *  - `roles.manage` can never be granted — it stays with the super admin alone, as it does for roles;
 *  - an override must *change* something: allowing what the role already grants, or denying what it does not, is refused;
 *  - an expiry is a date from today up to two years ahead and ends that day (UTC); expired rows stop applying at once and are
 *    removed by the nightly cleanup after 30 days;
 *  - at most MAX_OVERRIDES per person; every change is audited with old and new effect.
 */
final class UserPermissionService
{
    public const MAX_OVERRIDES = 100;
    private const NEVER_GRANTED = ['roles.manage'];

    public function __construct(
        private readonly UserAdminRepository $admin,
        private readonly PermissionRepository $permissions,
        private readonly RoleAdminRepository $roles,
        private readonly PermissionService $resolver,
        private readonly Db $db,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{user:array<string,mixed>,overrides:list<array<string,mixed>>,catalogue:array<string,list<array{id:int,name:string,label:string}>>,roleNames:list<string>,effective:int,editable:bool}
     * @throws DomainRuleException
     */
    public function panel(string $publicId, User $actor): array
    {
        $user = $this->target($publicId);
        $overrides = $this->db->select(
            'SELECT p.name, p.label, p.module, up.effect, up.expires_at, up.note, up.created_at, g.name AS granted_by_name,
                    (up.expires_at IS NOT NULL AND up.expires_at <= UTC_TIMESTAMP()) AS expired
             FROM user_permissions up JOIN permissions p ON p.id = up.permission_id LEFT JOIN users g ON g.id = up.granted_by
             WHERE up.user_id = :u ORDER BY p.module, p.name',
            ['u' => $user['id']],
        );
        $roleNames = $this->roles->grantedNames((int) $user['role_id']);
        foreach ($overrides as &$o) {
            $o['role_has'] = in_array($o['name'], $roleNames, true);
        }
        unset($o);
        $model = User::fromRow($user);

        return [
            'user' => $user,
            'overrides' => $overrides,
            'catalogue' => $this->roles->catalogue(),
            'roleNames' => $roleNames,
            'effective' => count($this->resolver->effectivePermissions($model)),
            'editable' => $this->mayEdit($user, $actor),
        ];
    }

    /**
     * @throws DomainRuleException|ValidationException
     */
    public function set(string $publicId, string $permission, string $effect, ?string $expiresOn, ?string $note, User $actor): void
    {
        $user = $this->target($publicId);
        $this->assertMayEdit($user, $actor);

        $errors = [];
        $perm = $this->db->selectOne('SELECT id, name FROM permissions WHERE name = :n', ['n' => $permission]);
        if ($perm === null) {
            $errors['permission'] = ['Choose a permission from the list.'];
        } elseif ($effect === 'allow' && in_array($permission, self::NEVER_GRANTED, true)) {
            $errors['permission'] = ['This permission stays with the super admin and cannot be granted to one person.'];
        }
        if (!in_array($effect, ['allow', 'deny'], true)) {
            $errors['effect'] = ['Choose allow or deny.'];
        }
        $expires = null;
        $expiresOn = $expiresOn === null ? '' : trim($expiresOn);
        if ($expiresOn !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiresOn, new \DateTimeZone('UTC'));
            $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
            if ($d === false || $d->format('Y-m-d') !== $expiresOn) {
                $errors['expires_on'] = ['Enter the last day as a date (YYYY-MM-DD).'];
            } elseif ($d < $today || $d > $today->modify('+730 days')) {
                $errors['expires_on'] = ['The last day must be today or later, and within two years.'];
            } else {
                $expires = $d->format('Y-m-d') . ' 23:59:59';
            }
        }
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 200) {
            $errors['note'] = ['The note is too long (200 characters at most).'];
        }
        if ($errors === [] && $perm !== null && in_array($effect, ['allow', 'deny'], true)) {
            $roleHas = in_array($permission, $this->roles->grantedNames((int) $user['role_id']), true);
            if ($effect === 'allow' && $roleHas) {
                $errors['permission'] = ['Their role already grants this — nothing to add.'];
            } elseif ($effect === 'deny' && !$roleHas) {
                $errors['permission'] = ['Their role does not grant this — there is nothing to take away.'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        \assert($perm !== null);

        $existing = $this->db->selectOne('SELECT effect, expires_at FROM user_permissions WHERE user_id = :u AND permission_id = :p', ['u' => $user['id'], 'p' => $perm['id']]);
        if ($existing === null && (int) $this->db->selectValue('SELECT COUNT(*) FROM user_permissions WHERE user_id = :u', ['u' => $user['id']], 0) >= self::MAX_OVERRIDES) {
            throw new ValidationException(['permission' => ['This person already has ' . self::MAX_OVERRIDES . ' overrides. Remove some first.']]);
        }
        $this->db->affectingStatement(
            'INSERT INTO user_permissions (user_id, permission_id, effect, expires_at, note, granted_by)
             VALUES (:u, :p, :e, :x, :n, :g)
             ON DUPLICATE KEY UPDATE effect = VALUES(effect), expires_at = VALUES(expires_at), note = VALUES(note), granted_by = VALUES(granted_by), created_at = UTC_TIMESTAMP()',
            ['u' => $user['id'], 'p' => $perm['id'], 'e' => $effect, 'x' => $expires, 'n' => ($note === '' ? null : $note), 'g' => $actor->id],
        );
        $this->resolver->forget((int) $user['id']);
        $this->audit->log(
            'permission_override_set', 'users', 'user', (int) $user['id'],
            $existing === null ? null : [$permission => $existing['effect'] . ($existing['expires_at'] ? ' until ' . substr((string) $existing['expires_at'], 0, 10) : '')],
            [$permission => $effect . ($expires !== null ? ' until ' . substr($expires, 0, 10) : ''), 'note' => $note],
            null, $actor,
        );
    }

    /** @throws DomainRuleException */
    public function remove(string $publicId, string $permission, User $actor): bool
    {
        $user = $this->target($publicId);
        $this->assertMayEdit($user, $actor);
        $row = $this->db->selectOne(
            'SELECT up.effect FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = :u AND p.name = :n',
            ['u' => $user['id'], 'n' => $permission],
        );
        if ($row === null) {
            return false;
        }
        $this->db->affectingStatement(
            'DELETE up FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = :u AND p.name = :n',
            ['u' => $user['id'], 'n' => $permission],
        );
        $this->resolver->forget((int) $user['id']);
        $this->audit->log('permission_override_removed', 'users', 'user', (int) $user['id'], [$permission => $row['effect']], null, null, $actor);

        return true;
    }

    /** Back to exactly what the role gives. @return int overrides removed @throws DomainRuleException */
    public function clear(string $publicId, User $actor): int
    {
        $user = $this->target($publicId);
        $this->assertMayEdit($user, $actor);
        $n = $this->db->affectingStatement('DELETE FROM user_permissions WHERE user_id = :u', ['u' => $user['id']]);
        $this->resolver->forget((int) $user['id']);
        if ($n > 0) {
            $this->audit->log('permission_overrides_cleared', 'users', 'user', (int) $user['id'], ['overrides' => $n], null, null, $actor);
        }

        return $n;
    }

    /** Overrides that ended more than 30 days ago (nightly). */
    public function pruneExpired(): int
    {
        return $this->db->affectingStatement('DELETE FROM user_permissions WHERE expires_at IS NOT NULL AND expires_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)');
    }

    // ---- rules --------------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function target(string $publicId): array
    {
        return $this->admin->findByPublicId($publicId) ?? throw new DomainRuleException('USER_MISSING', 'That user no longer exists.', [], 404);
    }

    /** @param array<string,mixed> $user */
    private function mayEdit(array $user, User $actor): bool
    {
        return $actor->isSuperAdmin() && $user['role_name'] !== 'super_admin';
    }

    /** @param array<string,mixed> $user @throws DomainRuleException */
    private function assertMayEdit(array $user, User $actor): void
    {
        if (!$actor->isSuperAdmin()) {
            throw new DomainRuleException('NOT_SUPER_ADMIN', 'Only a super admin can change individual permissions.', [], 403);
        }
        if ($user['role_name'] === 'super_admin') {
            throw new DomainRuleException('SUPER_ADMIN_TARGET', 'A super admin already holds every permission, so there is nothing to change.', [], 422);
        }
    }
}
