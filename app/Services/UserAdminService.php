<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\AuthService;
use App\Auth\RememberMe;
use App\Auth\TrustedDevice;
use App\Auth\TwoFactor;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Models\User;
use App\Repositories\SessionRepository;
use App\Repositories\UserAdminRepository;
use App\Repositories\UserRepository;
use App\Support\Db;
use App\Support\Hash;
use App\Support\Ulid;

/**
 * Creating and administering staff accounts. The rules that keep an organisation from locking itself out or
 * escalating privilege are enforced here, not in the screens:
 *
 *  - only a super admin may create, edit, deactivate or otherwise act on a super admin, or grant that role;
 *  - nobody changes their own role, active state or branch reach (someone else must);
 *  - the last active super admin can never be deactivated or demoted;
 *  - only an admin / super admin may make someone organisation-wide;
 *  - a user who is not organisation-wide needs at least one branch, and their primary branch must be one of them.
 *
 * Every change is audited (no password material is ever written to the audit log).
 */
final class UserAdminService
{
    private const SUPER = 'super_admin';
    private const ADMINS = ['super_admin', 'admin'];

    public function __construct(
        private readonly UserAdminRepository $admin,
        private readonly UserRepository $users,
        private readonly Hash $hash,
        private readonly AuditService $audit,
        private readonly AuthService $auth,
        private readonly SessionRepository $sessions,
        private readonly RememberMe $remember,
        private readonly TrustedDevice $devices,
        private readonly TwoFactor $twoFactor,
        private readonly Db $db,
    ) {
    }

    /**
     * @param array<string,mixed> $input name, email, phone, role_id, primary_branch_id, branch_ids[], is_org_wide
     * @return array{public_id:string,temporary_password:string}
     * @throws ValidationException|DomainRuleException
     */
    public function create(array $input, User $actor): array
    {
        $data = $this->validated($input, null);
        $role = $this->roleName($data['role_id']);
        $this->assertMayGrant($actor, $role);
        $this->assertOrgWide($actor, $data['is_org_wide']);
        if ($this->users->emailExists($data['email'])) {
            throw new ValidationException(['email' => ['Another account already uses this email address.']]);
        }

        $temporary = $this->temporaryPassword();
        $publicId = Ulid::generate();

        $this->db->transaction(function () use ($data, $temporary, $publicId, $actor): void {
            $id = $this->users->create([
                'public_id' => $publicId, 'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'password_hash' => $this->hash->make($temporary), 'role_id' => $data['role_id'],
                'primary_branch_id' => $data['primary_branch_id'], 'is_org_wide' => $data['is_org_wide'] ? 1 : 0,
                'is_active' => 1, 'must_change_password' => 1,
            ]);
            $this->admin->syncBranches($id, $this->branchesToStore($data));
            $this->audit->log('user_created', 'users', 'user', $id, null, $this->snapshot($data), null, $actor);
        });

        return ['public_id' => $publicId, 'temporary_password' => $temporary];
    }

    /** @param array<string,mixed> $input @throws ValidationException|DomainRuleException */
    public function update(string $publicId, array $input, User $actor): void
    {
        $target = $this->target($publicId);
        $this->assertMayActOn($actor, $target);
        $data = $this->validated($input, (int) $target['id']);
        $newRole = $this->roleName($data['role_id']);
        $isSelf = (int) $target['id'] === $actor->id;

        if ($isSelf && ($data['role_id'] !== (int) $target['role_id'] || $data['is_org_wide'] !== (bool) $target['is_org_wide']
            || $this->branchesToStore($data) !== $target['branch_ids'])) {
            throw new DomainRuleException('USER_SELF', 'You cannot change your own role or branch access. Ask another administrator.', [], 422);
        }
        if ($newRole !== $target['role_name']) {
            $this->assertMayGrant($actor, $newRole);
            if ($target['role_name'] === self::SUPER && $this->admin->activeSuperAdminCount((int) $target['id']) === 0 && (bool) $target['is_active']) {
                throw new DomainRuleException('USER_LAST_SUPER', 'This is the last active super admin; they cannot be demoted.', [], 409);
            }
        }
        if ($data['is_org_wide'] !== (bool) $target['is_org_wide']) {
            $this->assertOrgWide($actor, $data['is_org_wide']);
        }

        $this->db->transaction(function () use ($target, $data, $actor): void {
            $this->admin->update((int) $target['id'], [
                'name' => $data['name'], 'phone' => $data['phone'], 'role_id' => $data['role_id'],
                'primary_branch_id' => $data['primary_branch_id'], 'is_org_wide' => $data['is_org_wide'] ? 1 : 0,
            ]);
            $this->admin->syncBranches((int) $target['id'], $this->branchesToStore($data));
            $this->audit->log('user_updated', 'users', 'user', (int) $target['id'], $this->snapshotRow($target), $this->snapshot($data), null, $actor);
        });
        // A changed role or branch reach must not keep working on the strength of an old session.
        if ($data['role_id'] !== (int) $target['role_id'] && !$isSelf) {
            $this->endSessions((int) $target['id']);
        }
    }

    /** @throws DomainRuleException */
    public function setActive(string $publicId, bool $active, User $actor): void
    {
        $target = $this->target($publicId);
        $this->assertMayActOn($actor, $target);
        if ((int) $target['id'] === $actor->id) {
            throw new DomainRuleException('USER_SELF', 'You cannot deactivate your own account.', [], 422);
        }
        if (!$active && $target['role_name'] === self::SUPER && $this->admin->activeSuperAdminCount((int) $target['id']) === 0) {
            throw new DomainRuleException('USER_LAST_SUPER', 'This is the last active super admin; they cannot be deactivated.', [], 409);
        }
        if ((bool) $target['is_active'] === $active) {
            return;
        }

        $this->admin->update((int) $target['id'], ['is_active' => $active ? 1 : 0]);
        if (!$active) {
            $this->endSessions((int) $target['id']);
        }
        $this->audit->log($active ? 'user_reactivated' : 'user_deactivated', 'users', 'user', (int) $target['id'], ['is_active' => !$active], ['is_active' => $active], null, $actor);
    }

    public function unlock(string $publicId, User $actor): void
    {
        $target = $this->target($publicId);
        $this->assertMayActOn($actor, $target);
        $this->admin->update((int) $target['id'], ['failed_login_count' => 0, 'locked_until' => null]);
        $this->audit->log('user_unlocked', 'users', 'user', (int) $target['id'], null, null, null, $actor);
    }

    /** Emails the person a link to choose a new password (the normal reset flow; no password is ever shown). */
    public function sendResetLink(string $publicId, Request $request, User $actor): void
    {
        $target = $this->target($publicId);
        $this->assertMayActOn($actor, $target);
        if (!(bool) $target['is_active']) {
            throw new DomainRuleException('USER_INACTIVE', 'This account is deactivated.', [], 422);
        }
        $this->auth->sendResetLink((string) $target['email'], $request);
        $this->audit->log('user_reset_link_sent', 'users', 'user', (int) $target['id'], null, null, null, $actor);
    }

    /** Sets a new temporary password (shown once to the administrator) and forces a change at next sign-in. */
    public function issueTemporaryPassword(string $publicId, User $actor): string
    {
        $target = $this->target($publicId);
        $this->assertMayActOn($actor, $target);
        if ((int) $target['id'] === $actor->id) {
            throw new DomainRuleException('USER_SELF', 'Change your own password from your account page.', [], 422);
        }
        $temporary = $this->temporaryPassword();
        $this->users->updatePasswordHash((int) $target['id'], $this->hash->make($temporary), false);
        $this->admin->update((int) $target['id'], ['must_change_password' => 1, 'failed_login_count' => 0, 'locked_until' => null]);
        $this->endSessions((int) $target['id']);
        $this->audit->log('user_password_reset', 'users', 'user', (int) $target['id'], null, null, 'temporary password issued', $actor);

        return $temporary;
    }

    public function signOutEverywhere(string $publicId, User $actor): void
    {
        $target = $this->target($publicId);
        $this->assertMayActOn($actor, $target);
        $this->endSessions((int) $target['id']);
        $this->audit->log('user_signed_out', 'users', 'user', (int) $target['id'], null, null, null, $actor);
    }

    /** For someone who lost their authenticator and recovery codes: removes 2FA so they can enrol again. */
    public function resetTwoFactor(string $publicId, User $actor): void
    {
        $target = $this->target($publicId);
        $this->assertMayActOn($actor, $target);
        $user = $this->users->findById((int) $target['id']);
        if ($user !== null && $user->twoFactorEnabled) {
            $this->twoFactor->disable($user, $actor);
        }
    }

    // ---- rules --------------------------------------------------------------------------------

    private function assertMayActOn(User $actor, array $target): void
    {
        if ($target['role_name'] === self::SUPER && !$actor->isSuperAdmin()) {
            throw new DomainRuleException('USER_PRIVILEGE', 'Only a super admin can change a super admin account.', [], 403);
        }
    }

    private function assertMayGrant(User $actor, string $roleName): void
    {
        if ($roleName === self::SUPER && !$actor->isSuperAdmin()) {
            throw new DomainRuleException('USER_PRIVILEGE', 'Only a super admin can grant the super admin role.', [], 403);
        }
    }

    private function assertOrgWide(User $actor, bool $orgWide): void
    {
        if ($orgWide && !in_array($actor->roleName, self::ADMINS, true)) {
            throw new DomainRuleException('USER_PRIVILEGE', 'Only an administrator can give someone access to every branch.', [], 403);
        }
    }

    // ---- input ----------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $in
     * @return array{name:string,email:string,phone:?string,role_id:int,primary_branch_id:?int,branch_ids:list<int>,is_org_wide:bool}
     * @throws ValidationException
     */
    private function validated(array $in, ?int $existingId): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = ['Enter the person\'s name (up to 120 characters).'];
        }
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($existingId === null && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180)) {
            $errors['email'] = ['Enter a valid email address.'];
        }
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($phone !== '' && preg_match('/^[0-9+()\-\s]{7,30}$/', $phone) !== 1) {
            $errors['phone'] = ['Enter a valid phone number, or leave it blank.'];
        }

        $roleId = (int) ($in['role_id'] ?? 0);
        if (!in_array($roleId, array_column($this->admin->roles(), 'id'), true)) {
            $errors['role_id'] = ['Choose a role.'];
        }

        $valid = array_column($this->admin->branches(), 'id');
        $branchIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['branch_ids'] ?? [])), static fn (int $b): bool => $b > 0)));
        if (array_diff($branchIds, $valid) !== []) {
            $errors['branch_ids'] = ['One of the chosen branches does not exist.'];
        }
        $primary = ($in['primary_branch_id'] ?? '') !== '' && ctype_digit((string) $in['primary_branch_id']) ? (int) $in['primary_branch_id'] : null;
        $orgWide = filter_var($in['is_org_wide'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$orgWide) {
            if ($primary !== null && !in_array($primary, $branchIds, true)) {
                $branchIds[] = $primary;   // the primary branch is always one of the user's branches
            }
            if ($branchIds === []) {
                $errors['branch_ids'] = ['Choose at least one branch (or make the user organisation-wide).'];
            } elseif ($primary === null) {
                $primary = $branchIds[0];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['name' => $name, 'email' => $email, 'phone' => $phone !== '' ? $phone : null, 'role_id' => $roleId, 'primary_branch_id' => $primary, 'branch_ids' => $branchIds, 'is_org_wide' => $orgWide];
    }

    /** @param array{branch_ids:list<int>,primary_branch_id:?int} $data @return list<int> */
    private function branchesToStore(array $data): array
    {
        $ids = $data['branch_ids'];
        if ($data['primary_branch_id'] !== null && !in_array($data['primary_branch_id'], $ids, true)) {
            $ids[] = $data['primary_branch_id'];
        }
        sort($ids);

        return $ids;
    }

    /** @return array<string,mixed> */
    private function target(string $publicId): array
    {
        return $this->admin->findByPublicId($publicId) ?? throw new DomainRuleException('USER_MISSING', 'That user no longer exists.', [], 404);
    }

    private function roleName(int $roleId): string
    {
        foreach ($this->admin->roles() as $r) {
            if ($r['id'] === $roleId) {
                return $r['name'];
            }
        }

        return '';
    }

    private function endSessions(int $userId): void
    {
        $this->sessions->deleteForUserExcept($userId, '');
        $this->remember->revokeAll($userId);
        $this->devices->revokeAll($userId);
    }

    /** 14 characters, no look-alikes (0/O, 1/l/I), always with upper, lower and a digit. */
    private function temporaryPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789'];
        $all = implode('', $sets);
        $chars = array_map(static fn (string $s): string => $s[random_int(0, strlen($s) - 1)], $sets);
        while (count($chars) < 14) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function snapshot(array $data): array
    {
        return ['name' => $data['name'], 'email' => $data['email'] ?? null, 'role_id' => $data['role_id'], 'primary_branch_id' => $data['primary_branch_id'], 'branch_ids' => $this->branchesToStore($data), 'is_org_wide' => $data['is_org_wide']];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function snapshotRow(array $row): array
    {
        return ['name' => $row['name'], 'role_id' => (int) $row['role_id'], 'primary_branch_id' => $row['primary_branch_id'] !== null ? (int) $row['primary_branch_id'] : null, 'branch_ids' => $row['branch_ids'], 'is_org_wide' => (bool) $row['is_org_wide']];
    }
}
