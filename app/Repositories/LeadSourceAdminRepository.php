<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** SQL for Admin → Lead sources (the "where did this lead come from" list). */
final class LeadSourceAdminRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id:int,name:string,is_active:bool,sort_order:int,leads:int}> in display order, with how many leads use each */
    public function all(): array
    {
        $rows = $this->db->select(
            'SELECT s.id, s.name, s.is_active, s.sort_order, (SELECT COUNT(*) FROM leads l WHERE l.source_id = s.id AND l.deleted_at IS NULL) AS leads
             FROM lead_sources s ORDER BY s.sort_order, s.name',
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'is_active' => (bool) $r['is_active'], 'sort_order' => (int) $r['sort_order'], 'leads' => (int) $r['leads'],
        ], $rows);
    }

    /** @return array{id:int,name:string,is_active:bool,sort_order:int}|null */
    public function find(int $id): ?array
    {
        $r = $this->db->selectOne('SELECT id, name, is_active, sort_order FROM lead_sources WHERE id = :id', ['id' => $id]);

        return $r === null ? null : ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'is_active' => (bool) $r['is_active'], 'sort_order' => (int) $r['sort_order']];
    }

    public function nameExists(string $name, int $exceptId = 0): bool
    {
        return $this->db->exists('SELECT 1 FROM lead_sources WHERE name = :n AND id <> :id', ['n' => $name, 'id' => $exceptId]);
    }

    public function create(string $name, int $sortOrder): int
    {
        return (int) $this->db->insertRow('lead_sources', ['name' => $name, 'is_active' => 1, 'sort_order' => $sortOrder]);
    }

    public function rename(int $id, string $name): void
    {
        $this->db->affectingStatement('UPDATE lead_sources SET name = :n WHERE id = :id', ['n' => $name, 'id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->affectingStatement('UPDATE lead_sources SET is_active = :a WHERE id = :id', ['a' => $active ? 1 : 0, 'id' => $id]);
    }

    public function setOrder(int $id, int $order): void
    {
        $this->db->affectingStatement('UPDATE lead_sources SET sort_order = :o WHERE id = :id', ['o' => $order, 'id' => $id]);
    }

    public function activeCount(): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM lead_sources WHERE is_active = 1');
    }
}
