<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;
use App\Support\Sql;

/**
 * SQL for CMS pages. The staff side sees everything; the public side (`live*`, `sitemap`) can only ever see a page that is
 * published, not in the trash and inside its [publish_at, unpublish_at) window — the rule lives in one constant so no public
 * query can forget it. Pages belong to the organisation, not a branch, so there is no branch scope.
 */
final class CmsPageRepository
{
    /** A page an anonymous visitor may see right now. */
    public const LIVE = "p.status = 'published' AND p.deleted_at IS NULL AND (p.publish_at IS NULL OR p.publish_at <= UTC_TIMESTAMP()) AND (p.unpublish_at IS NULL OR p.unpublish_at > UTC_TIMESTAMP())";

    /** The state an editor sees: trash | scheduled | expired | live | draft | review | archived. */
    private const STATE = "CASE WHEN p.deleted_at IS NOT NULL THEN 'trash'
        WHEN p.status = 'published' AND p.publish_at IS NOT NULL AND p.publish_at > UTC_TIMESTAMP() THEN 'scheduled'
        WHEN p.status = 'published' AND p.unpublish_at IS NOT NULL AND p.unpublish_at <= UTC_TIMESTAMP() THEN 'expired'
        WHEN p.status = 'published' THEN 'live' ELSE p.status END";

    private const LIST_COLUMNS = 'p.id, p.public_id, p.path, p.title, p.status, p.publish_at, p.unpublish_at, p.published_at, p.template, p.robots, p.word_count, p.version, p.updated_at, p.deleted_at, u.name AS updated_by_name';

    public const TABS = ['all', 'draft', 'review', 'live', 'scheduled', 'expired', 'archived', 'trash'];

    public function __construct(private readonly Db $db)
    {
    }

    // ---- staff ---------------------------------------------------------------------------------------------

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function adminPage(string $tab, string $search, int $page, int $perPage = 20): array
    {
        $where = [$tab === 'trash' ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL'];
        $bind = [];
        if (in_array($tab, ['draft', 'review', 'archived'], true)) {
            $where[] = 'p.status = :status';
            $bind['status'] = $tab;
        } elseif (in_array($tab, ['live', 'scheduled', 'expired'], true)) {
            $where[] = self::STATE . ' = :state';
            $bind['state'] = $tab;
        }
        if ($search !== '') {
            $where[] = '(p.title LIKE :q1 OR p.path LIKE :q2)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $bind['q1'] = $like;
            $bind['q2'] = $like;
        }
        $condition = implode(' AND ', $where);
        $limit = max(1, min($perPage, 100));
        $offset = (max(1, $page) - 1) * $limit;

        return [
            'total' => (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_pages p WHERE {$condition}", $bind),
            'rows' => $this->db->select(
                'SELECT ' . self::LIST_COLUMNS . ', ' . self::STATE . " AS state FROM cms_pages p LEFT JOIN users u ON u.id = p.updated_by WHERE {$condition}
                 ORDER BY p.updated_at DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}",
                $bind,
            ),
        ];
    }

    /** @return array<string,int> tab => count */
    public function counts(): array
    {
        $out = array_fill_keys(self::TABS, 0);
        foreach ($this->db->select('SELECT ' . self::STATE . ' AS state, COUNT(*) AS n FROM cms_pages p GROUP BY state') as $r) {
            $out[(string) $r['state']] = (int) $r['n'];
            if ($r['state'] !== 'trash') {
                $out['all'] += (int) $r['n'];
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $publicId): ?array
    {
        return $this->db->selectOne(
            'SELECT p.*, ' . self::STATE . ' AS state, a.name AS author_name, u.name AS updated_by_name FROM cms_pages p
             LEFT JOIN users a ON a.id = p.author_id LEFT JOIN users u ON u.id = p.updated_by WHERE p.public_id = :p',
            ['p' => $publicId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function findMany(array $publicIds): array
    {
        $publicIds = array_values(array_unique(array_filter($publicIds, static fn ($v): bool => is_string($v) && preg_match('/^[0-9A-Z]{26}$/D', $v) === 1)));
        if ($publicIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($publicIds), '?'));

        return $this->db->select("SELECT p.*, " . self::STATE . " AS state FROM cms_pages p WHERE p.public_id IN ({$ph})", $publicIds);
    }

    public function pathExists(string $path, int $exceptId = 0): bool
    {
        return $this->db->exists('SELECT 1 FROM cms_pages WHERE path = :p AND id <> :id', ['p' => $path, 'id' => $exceptId]);
    }

    public function titleCount(string $title, int $exceptId): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_pages WHERE deleted_at IS NULL AND id <> :id AND COALESCE(NULLIF(meta_title, \'\'), title) = :t', ['id' => $exceptId, 't' => $title], 0);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('cms_pages', $data);
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
        $this->db->affectingStatement('UPDATE cms_pages SET ' . implode(', ', $sets) . ' WHERE id = :id', $bind);
    }

    public function delete(int $id): void
    {
        $this->db->affectingStatement('DELETE FROM cms_pages WHERE id = :id', ['id' => $id]);
    }

    /** Pages in the trash for longer than $days (nightly purge). */
    public function purgeTrash(int $days): int
    {
        return $this->db->affectingStatement('DELETE FROM cms_pages WHERE deleted_at IS NOT NULL AND deleted_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)', ['d' => $days]);
    }

    // ---- revisions ------------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> newest first */
    public function revisions(int $pageId): array
    {
        return $this->db->select(
            'SELECT r.version, r.title, r.note, r.created_at, u.name AS created_by_name, CHAR_LENGTH(r.body_source) AS chars
             FROM cms_revisions r LEFT JOIN users u ON u.id = r.created_by WHERE r.page_id = :p ORDER BY r.version DESC',
            ['p' => $pageId],
        );
    }

    /** @return array<string,mixed>|null */
    public function revision(int $pageId, int $version): ?array
    {
        return $this->db->selectOne('SELECT r.*, u.name AS created_by_name FROM cms_revisions r LEFT JOIN users u ON u.id = r.created_by WHERE r.page_id = :p AND r.version = :v', ['p' => $pageId, 'v' => $version]);
    }

    /** @param array<string,mixed> $snapshot */
    public function addRevision(int $pageId, int $version, string $title, string $body, array $snapshot, ?string $note, ?int $userId): void
    {
        $this->db->insertRow('cms_revisions', [
            'page_id' => $pageId, 'version' => $version, 'title' => $title, 'body_source' => $body,
            'snapshot' => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'note' => $note, 'created_by' => $userId,
        ]);
    }

    /** Keep the newest $keep revisions of a page. */
    public function pruneRevisions(int $pageId, int $keep): void
    {
        $this->db->affectingStatement(
            'DELETE FROM cms_revisions WHERE page_id = :p AND version <= (SELECT v FROM (SELECT CAST(MAX(version) AS SIGNED) - :k AS v FROM cms_revisions WHERE page_id = :p2) x)',
            ['p' => $pageId, 'k' => $keep, 'p2' => $pageId],
        );
    }

    // ---- preview links --------------------------------------------------------------------------------------

    public function addPreviewToken(int $pageId, string $hash, string $expiresAt, ?int $userId): void
    {
        $this->db->insertRow('cms_preview_tokens', ['page_id' => $pageId, 'token_hash' => $hash, 'expires_at' => $expiresAt, 'created_by' => $userId]);
    }

    public function activePreviewTokens(int $pageId): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_preview_tokens WHERE page_id = :p AND expires_at > UTC_TIMESTAMP()', ['p' => $pageId], 0);
    }

    public function revokePreviewTokens(int $pageId): int
    {
        return $this->db->affectingStatement('DELETE FROM cms_preview_tokens WHERE page_id = :p', ['p' => $pageId]);
    }

    /** The page a valid (unexpired) preview token opens, whatever its status — except one in the trash. @return array<string,mixed>|null */
    public function pageForPreviewToken(string $hash): ?array
    {
        return $this->db->selectOne(
            'SELECT p.* FROM cms_preview_tokens t JOIN cms_pages p ON p.id = t.page_id WHERE t.token_hash = :h AND t.expires_at > UTC_TIMESTAMP() AND p.deleted_at IS NULL',
            ['h' => $hash],
        );
    }

    public function prunePreviewTokens(): int
    {
        return $this->db->affectingStatement('DELETE FROM cms_preview_tokens WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
    }

    // ---- public --------------------------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function live(string $path): ?array
    {
        return $this->db->selectOne(
            'SELECT p.id, p.public_id, p.path, p.title, p.summary, p.body_html, p.template, p.meta_title, p.meta_description, p.canonical_url, p.robots,
                    p.og_title, p.og_description, p.featured_image, p.featured_alt, p.faq, p.word_count, p.published_at, p.updated_at, a.name AS author_name
             FROM cms_pages p LEFT JOIN users a ON a.id = p.author_id WHERE p.path = :p AND ' . self::LIVE,
            ['p' => $path],
        );
    }

    /** @return list<array{path:string,updated_at:string,priority:string,changefreq:string}> */
    public function sitemap(int $limit = 5000): array
    {
        return array_map(static fn (array $r): array => [
            'path' => (string) $r['path'], 'updated_at' => (string) $r['updated_at'], 'priority' => number_format((float) $r['sitemap_priority'], 1), 'changefreq' => (string) $r['sitemap_changefreq'],
        ], $this->db->select(
            "SELECT p.path, p.updated_at, p.sitemap_priority, p.sitemap_changefreq FROM cms_pages p WHERE p.in_sitemap = 1 AND p.robots = 'index' AND " . self::LIVE
            . ' ORDER BY p.path LIMIT ' . max(1, min($limit, 50000)),
        ));
    }

    /** Every path currently taken by a page (live or not), for the internal-link checker. @return list<string> */
    public function allPaths(): array
    {
        return array_map('strval', array_column($this->db->select('SELECT path FROM cms_pages WHERE deleted_at IS NULL'), 'path'));
    }
}
