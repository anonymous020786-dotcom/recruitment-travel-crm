<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\BranchAdminRepository;
use App\Support\Db;
use App\Support\Ulid;

/**
 * Creating and maintaining branches.
 *
 *  - name and code are unique; the code is upper-case letters/digits/hyphens (it is how staff refer to the branch);
 *  - a branch is never deleted (leads, invoices and history point at it) — it is deactivated, which hides it from every
 *    "choose a branch" list but keeps its records;
 *  - the last active branch cannot be deactivated, and neither can a branch that would leave an active, non-organisation-wide
 *    person with no active branch (move them first) — otherwise they could no longer see any data;
 *  - every change is audited with what changed.
 */
final class BranchAdminService
{
    private const FIELDS = ['name', 'code', 'address_line1', 'address_line2', 'city', 'state', 'country', 'phone', 'email'];

    public function __construct(
        private readonly BranchAdminRepository $branches,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
        private readonly Db $db,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return string the new branch's public id
     * @throws AuthorizationException|ValidationException
     */
    public function create(array $input, User $actor): string
    {
        $this->assertMay($actor);
        $data = $this->validated($input, 0);
        $publicId = Ulid::generate();

        $this->db->transaction(function () use ($data, $publicId, $actor): void {
            $id = $this->branches->create($data + ['public_id' => $publicId, 'is_active' => 1]);
            $this->audit->log('branch_created', 'branches', 'branch', $id, null, ['name' => $data['name'], 'code' => $data['code']], null, $actor);
        });

        return $publicId;
    }

    /**
     * @param array<string,mixed> $input
     * @throws AuthorizationException|DomainRuleException|ValidationException
     */
    public function update(string $publicId, array $input, User $actor): void
    {
        $this->assertMay($actor);
        $branch = $this->load($publicId);
        $data = $this->validated($input, (int) $branch['id']);

        $changed = array_filter($data, static fn ($v, $k): bool => ($branch[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return;
        }
        $this->db->transaction(function () use ($branch, $changed, $actor): void {
            $this->branches->update((int) $branch['id'], $changed);
            $this->audit->log('branch_updated', 'branches', 'branch', (int) $branch['id'], array_intersect_key($branch, $changed), $changed, null, $actor);
        });
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function deactivate(string $publicId, User $actor): void
    {
        $this->assertMay($actor);
        $branch = $this->load($publicId);
        if (!(bool) $branch['is_active']) {
            return;
        }
        if ($this->branches->activeCount() <= 1) {
            throw new DomainRuleException('BRANCH_LAST', 'This is the only active branch — the organisation needs at least one.', [], 422);
        }
        $stranded = $this->branches->peopleStrandedBy((int) $branch['id']);
        if ($stranded > 0) {
            throw new DomainRuleException('BRANCH_HAS_PEOPLE', $stranded . ($stranded === 1 ? ' active person is' : ' active people are') . ' still assigned only to this branch. Move them to another branch in Admin → Users first.', [], 422);
        }

        $this->flip($branch, false, 'branch_deactivated', $actor);
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function reactivate(string $publicId, User $actor): void
    {
        $this->assertMay($actor);
        $branch = $this->load($publicId);
        if ((bool) $branch['is_active']) {
            return;
        }

        $this->flip($branch, true, 'branch_reactivated', $actor);
    }

    /** @param array<string,mixed> $branch */
    private function flip(array $branch, bool $active, string $action, User $actor): void
    {
        $this->db->transaction(function () use ($branch, $active, $action, $actor): void {
            $this->branches->update((int) $branch['id'], ['is_active' => $active ? 1 : 0]);
            $this->audit->log($action, 'branches', 'branch', (int) $branch['id'], ['is_active' => (int) $branch['is_active']], ['is_active' => $active ? 1 : 0, 'name' => $branch['name']], null, $actor);
        });
    }

    /** @throws AuthorizationException */
    private function assertMay(User $actor): void
    {
        if (!$this->permissions->userCan($actor, 'branches.manage')) {
            throw AuthorizationException::forPermission('branches.manage');
        }
    }

    /** @return array<string,mixed> */
    private function load(string $publicId): array
    {
        return $this->branches->find($publicId) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Branch not found.', [], 404);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string|null>
     * @throws ValidationException
     */
    private function validated(array $input, int $exceptId): array
    {
        $errors = [];
        $clean = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string) ($input[$f] ?? ''));
            $clean[$f] = $v === '' ? null : $v;
        }

        $name = $clean['name'];
        if ($name === null || mb_strlen($name) > 120) {
            $errors['name'] = ['Give the branch a name of up to 120 characters.'];
        } elseif ($this->branches->nameExists($name, $exceptId)) {
            $errors['name'] = ['Another branch already has this name.'];
        }

        $code = $clean['code'] !== null ? strtoupper($clean['code']) : null;
        $clean['code'] = $code;
        if ($code === null || preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/D', $code) !== 1 || strlen($code) > 20) {
            $errors['code'] = ['Use a short code of letters, numbers and single hyphens (20 characters at most), e.g. MUM or DEL-2.'];
        } elseif ($this->branches->codeExists($code, $exceptId)) {
            $errors['code'] = ['Another branch already uses this code.'];
        }

        foreach (['address_line1' => 180, 'address_line2' => 180, 'city' => 90, 'state' => 90] as $f => $max) {
            if ($clean[$f] !== null && (mb_strlen($clean[$f]) > $max || preg_match('/[\x00-\x1F]/', $clean[$f]) === 1)) {
                $errors[$f] = ["Keep this to a single line of up to {$max} characters."];
            }
        }
        if ($clean['country'] !== null) {
            $clean['country'] = strtoupper($clean['country']);
            if (preg_match('/^[A-Z]{2}$/D', $clean['country']) !== 1) {
                $errors['country'] = ['Use the two-letter country code, e.g. IN.'];
            }
        }
        if ($clean['phone'] !== null && preg_match('/^[0-9+()\-\s]{7,30}$/D', $clean['phone']) !== 1) {
            $errors['phone'] = ['Phone must be 7–30 digits (spaces, +, - and brackets allowed).'];
        }
        if ($clean['email'] !== null) {
            $clean['email'] = strtolower($clean['email']);
            if (mb_strlen($clean['email']) > 180 || filter_var($clean['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = ['Enter a valid email address.'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }
}
