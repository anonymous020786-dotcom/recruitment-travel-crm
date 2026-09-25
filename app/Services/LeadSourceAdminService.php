<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\LeadSourceAdminRepository;
use App\Support\Db;

/**
 * Maintaining the list of lead sources.
 *
 *  - names are unique (ignoring case), one line, up to 80 characters;
 *  - a source is never deleted (leads and reports point at it) — deactivating hides it from the "source" dropdown on new leads
 *    while old leads keep it;
 *  - "Website" is what the public enquiry inbox stamps on leads it converts, so that one cannot be renamed or switched off;
 *  - at least one source must stay active (the lead form needs something to offer);
 *  - the order is the order of the dropdown; moving one swaps it with its neighbour;
 *  - every change is audited.
 */
final class LeadSourceAdminService
{
    public const PROTECTED_NAME = 'Website';

    public function __construct(
        private readonly LeadSourceAdminRepository $sources,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
        private readonly Db $db,
    ) {
    }

    /** @throws AuthorizationException|ValidationException */
    public function create(string $name, User $actor): int
    {
        $this->assertMay($actor);
        $name = $this->validName($name, 0);
        $last = 0;
        foreach ($this->sources->all() as $s) {
            $last = max($last, $s['sort_order']);
        }

        return $this->db->transaction(function () use ($name, $last, $actor): int {
            $id = $this->sources->create($name, $last + 1);
            $this->audit->log('lead_source_created', 'settings', 'lead_source', $id, null, ['name' => $name], null, $actor);

            return $id;
        });
    }

    /** @throws AuthorizationException|DomainRuleException|ValidationException */
    public function rename(int $id, string $name, User $actor): void
    {
        $this->assertMay($actor);
        $source = $this->load($id);
        $name = $this->validName($name, $id);
        if ($name === $source['name']) {
            return;
        }
        if ($this->isProtected($source['name'])) {
            throw new DomainRuleException('LEAD_SOURCE_PROTECTED', '“' . self::PROTECTED_NAME . '” is used by the public enquiry inbox and cannot be renamed.', [], 422);
        }
        $this->db->transaction(function () use ($id, $name, $source, $actor): void {
            $this->sources->rename($id, $name);
            $this->audit->log('lead_source_renamed', 'settings', 'lead_source', $id, ['name' => $source['name']], ['name' => $name], null, $actor);
        });
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function setActive(int $id, bool $active, User $actor): void
    {
        $this->assertMay($actor);
        $source = $this->load($id);
        if ($source['is_active'] === $active) {
            return;
        }
        if (!$active) {
            if ($this->isProtected($source['name'])) {
                throw new DomainRuleException('LEAD_SOURCE_PROTECTED', '“' . self::PROTECTED_NAME . '” is used by the public enquiry inbox and cannot be switched off.', [], 422);
            }
            if ($this->sources->activeCount() <= 1) {
                throw new DomainRuleException('LEAD_SOURCE_LAST', 'At least one source must stay active — the lead form needs something to offer.', [], 422);
            }
        }
        $this->db->transaction(function () use ($id, $active, $source, $actor): void {
            $this->sources->setActive($id, $active);
            $this->audit->log($active ? 'lead_source_activated' : 'lead_source_deactivated', 'settings', 'lead_source', $id, ['is_active' => (int) $source['is_active']], ['is_active' => (int) $active, 'name' => $source['name']], null, $actor);
        });
    }

    /** @param 'up'|'down' $direction @throws AuthorizationException|DomainRuleException */
    public function move(int $id, string $direction, User $actor): void
    {
        $this->assertMay($actor);
        $this->load($id);
        $list = $this->sources->all();
        $index = null;
        foreach ($list as $i => $s) {
            if ($s['id'] === $id) {
                $index = $i;
            }
        }
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index === null || $swap < 0 || $swap >= count($list)) {
            return;   // already at the end
        }
        [$list[$index], $list[$swap]] = [$list[$swap], $list[$index]];

        $this->db->transaction(function () use ($list, $id, $direction, $actor): void {
            foreach ($list as $position => $s) {
                if ($s['sort_order'] !== $position + 1) {
                    $this->sources->setOrder($s['id'], $position + 1);   // also tidies a list whose numbers were all 0
                }
            }
            $this->audit->log('lead_source_moved', 'settings', 'lead_source', $id, null, ['direction' => $direction], null, $actor);
        });
    }

    /** @throws AuthorizationException */
    private function assertMay(User $actor): void
    {
        if (!$this->permissions->userCan($actor, 'settings.manage')) {
            throw AuthorizationException::forPermission('settings.manage');
        }
    }

    /** @return array{id:int,name:string,is_active:bool,sort_order:int} */
    private function load(int $id): array
    {
        return $this->sources->find($id) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Source not found.', [], 404);
    }

    private function isProtected(string $name): bool
    {
        return strcasecmp($name, self::PROTECTED_NAME) === 0;
    }

    /** @throws ValidationException */
    private function validName(string $name, int $exceptId): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F]/', $name) === 1) {
            throw new ValidationException(['name' => ['Give the source a name of up to 80 characters.']]);
        }
        if ($this->sources->nameExists($name, $exceptId)) {
            throw new ValidationException(['name' => ['A source with this name already exists.']]);
        }

        return $name;
    }
}
