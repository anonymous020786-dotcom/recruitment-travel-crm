<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;
use App\Support\Sql;

/**
 * SQL for the blog. The staff side sees every post; the public side (`published*`, `sitemap`) can only ever see a post that
 * is `published` — the rule lives in one constant so no public query can forget it. Posts belong to the organisation, not
 * to a branch, so there is no branch scope here.
 */
final class BlogRepository
{
    private const PUBLIC = "b.status = 'published' AND b.published_at IS NOT NULL AND b.published_at <= UTC_TIMESTAMP()";
    private const LIST_COLUMNS = 'b.id, b.public_id, b.slug, b.title, b.excerpt, b.status, b.published_at, b.created_at, b.updated_at, u.name AS author_name';
    public const STATUSES = ['draft', 'published', 'archived'];

    public function __construct(private readonly Db $db)
    {
    }

    // ---- staff ---------------------------------------------------------------------------------------------

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function adminPage(string $status, string $search, int $page, int $perPage = 20): array
    {
        $where = ['1 = 1'];
        $bind = [];
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'b.status = :status';
            $bind['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(b.title LIKE :q1 OR b.slug LIKE :q2)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $bind['q1'] = $like;
            $bind['q2'] = $like;
        }
        $condition = implode(' AND ', $where);
        $limit = max(1, min($perPage, 100));
        $offset = (max(1, $page) - 1) * $limit;

        return [
            'total' => (int) $this->db->selectValue("SELECT COUNT(*) FROM blog_posts b WHERE {$condition}", $bind),
            'rows' => $this->db->select(
                'SELECT ' . self::LIST_COLUMNS . " FROM blog_posts b LEFT JOIN users u ON u.id = b.author_id WHERE {$condition}
                 ORDER BY b.updated_at DESC, b.id DESC LIMIT {$limit} OFFSET {$offset}",
                $bind,
            ),
        ];
    }

    /** @return array{total:int,draft:int,published:int,archived:int} */
    public function counts(): array
    {
        $out = ['total' => 0, 'draft' => 0, 'published' => 0, 'archived' => 0];
        foreach ($this->db->select('SELECT status, COUNT(*) AS n FROM blog_posts GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $publicId): ?array
    {
        return $this->db->selectOne('SELECT b.*, u.name AS author_name FROM blog_posts b LEFT JOIN users u ON u.id = b.author_id WHERE b.public_id = :p', ['p' => $publicId]);
    }

    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        return $this->db->exists('SELECT 1 FROM blog_posts WHERE slug = :s AND id <> :id', ['s' => $slug, 'id' => $exceptId]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('blog_posts', $data);
    }

    /** @param array<string,mixed> $data columns to change */
    public function update(int $id, array $data): void
    {
        $sets = [];
        $bind = ['id' => $id];
        foreach ($data as $column => $value) {
            $sets[] = Sql::assign((string) $column, 'c_');
            $bind['c_' . $column] = $value;
        }
        $this->db->affectingStatement('UPDATE blog_posts SET ' . implode(', ', $sets) . ' WHERE id = :id', $bind);
    }

    // ---- public --------------------------------------------------------------------------------------------

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function published(int $page, int $perPage = 10): array
    {
        $limit = max(1, min($perPage, 50));
        $offset = (max(1, $page) - 1) * $limit;

        return [
            'total' => (int) $this->db->selectValue('SELECT COUNT(*) FROM blog_posts b WHERE ' . self::PUBLIC),
            'rows' => $this->db->select(
                'SELECT b.slug, b.title, b.excerpt, b.body_html, b.published_at, b.updated_at FROM blog_posts b WHERE ' . self::PUBLIC
                . " ORDER BY b.published_at DESC, b.id DESC LIMIT {$limit} OFFSET {$offset}",
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    public function publishedBySlug(string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT b.slug, b.title, b.excerpt, b.body_html, b.published_at, b.updated_at, u.name AS author_name
             FROM blog_posts b LEFT JOIN users u ON u.id = b.author_id WHERE b.slug = :s AND ' . self::PUBLIC,
            ['s' => $slug],
        );
    }

    /** @return list<array{slug:string,updated_at:string}> */
    public function sitemap(int $limit = 5000): array
    {
        return array_map(static fn (array $r): array => ['slug' => (string) $r['slug'], 'updated_at' => (string) $r['updated_at']], $this->db->select(
            'SELECT b.slug, b.updated_at FROM blog_posts b WHERE ' . self::PUBLIC . ' ORDER BY b.published_at DESC LIMIT ' . max(1, min($limit, 50000)),
        ));
    }
}
