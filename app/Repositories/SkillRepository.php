<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * The global `skills` catalogue, shared across every candidate — same shape
 * as `PersonRepository::findOrCreate()`: look up by name first so the same
 * skill never accumulates duplicate rows.
 */
final class SkillRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findOrCreateByName(string $name, ?string $category = null): int
    {
        $name = trim($name);
        $existing = $this->db->selectOne('SELECT id FROM skills WHERE name = :n', ['n' => $name]);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return (int) $this->db->insertRow('skills', ['name' => $name, 'category' => $category]);
    }

    /** @return list<array{id:int,name:string,category:?string}> */
    public function search(string $term, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $rows = $this->db->select(
            'SELECT id, name, category FROM skills WHERE name LIKE :t ORDER BY name LIMIT ' . $limit,
            ['t' => $this->escapeLike($term) . '%'],
        );

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'category' => $r['category'] ?? null],
            $rows,
        );
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
