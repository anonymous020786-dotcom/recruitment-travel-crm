<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/** SQL for the CMS extras: redirects, snippets and the public menus. Organisation-wide, so no branch scope. */
final class CmsSiteRepository
{
    /** @var array<string,list<array<string,mixed>>> per-request menu cache */
    private array $menuCache = [];
    /** @var array<string,?string> per-request snippet cache (key => html, null = missing/off) */
    private array $snippetCache = [];

    public function __construct(private readonly Db $db)
    {
    }

    // ---- redirects ------------------------------------------------------------------------------------------

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function redirects(string $search, int $page, int $perPage = 50): array
    {
        $where = '1 = 1';
        $bind = [];
        if ($search !== '') {
            $where = '(r.from_path LIKE :q1 OR r.to_url LIKE :q2)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $bind = ['q1' => $like, 'q2' => $like];
        }
        $limit = max(1, min($perPage, 200));
        $offset = (max(1, $page) - 1) * $limit;

        return [
            'total' => (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_redirects r WHERE {$where}", $bind),
            'rows' => $this->db->select("SELECT r.*, u.name AS created_by_name FROM cms_redirects r LEFT JOIN users u ON u.id = r.created_by WHERE {$where}
                ORDER BY r.updated_at DESC, r.id DESC LIMIT {$limit} OFFSET {$offset}", $bind),
        ];
    }

    /** @return array<string,mixed>|null */
    public function redirect(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM cms_redirects WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function redirectFrom(string $fromPath): ?array
    {
        return $this->db->selectOne('SELECT * FROM cms_redirects WHERE from_path = :f', ['f' => $fromPath]);
    }

    /** @return array<string,mixed>|null the active redirect for a normalised path */
    public function activeRedirect(string $fromPath): ?array
    {
        return $this->db->selectOne('SELECT id, to_url, status_code, keep_query FROM cms_redirects WHERE from_path = :f AND is_active = 1', ['f' => $fromPath]);
    }

    public function countRedirects(): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_redirects', [], 0);
    }

    /** @param array<string,mixed> $data */
    public function insertRedirect(array $data): int
    {
        return (int) $this->db->insertRow('cms_redirects', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateRedirect(int $id, array $data): void
    {
        $this->update('cms_redirects', $id, $data);
    }

    public function deleteRedirect(int $id): int
    {
        return $this->db->affectingStatement('DELETE FROM cms_redirects WHERE id = :id', ['id' => $id]);
    }

    /** Point every redirect that ends at $oldTarget straight at $newTarget (no chains). @return int redirects changed */
    public function retarget(string $oldTarget, string $newTarget, int $exceptId): int
    {
        return $this->db->affectingStatement(
            'UPDATE cms_redirects SET to_url = :n WHERE to_url = :o AND id <> :id',
            ['n' => $newTarget, 'o' => $oldTarget, 'id' => $exceptId],
        );
    }

    public function recordHit(int $id): void
    {
        $this->db->affectingStatement('UPDATE cms_redirects SET hits = hits + 1, last_hit_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
    }

    // ---- snippets -------------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function snippets(): array
    {
        return $this->db->select('SELECT s.id, s.key_name, s.title, s.is_active, s.updated_at, CHAR_LENGTH(s.body_source) AS chars, u.name AS updated_by_name
            FROM cms_snippets s LEFT JOIN users u ON u.id = s.updated_by ORDER BY s.key_name');
    }

    /** @return array<string,mixed>|null */
    public function snippet(string $key): ?array
    {
        return $this->db->selectOne('SELECT * FROM cms_snippets WHERE key_name = :k', ['k' => $key]);
    }

    /** The HTML of an active snippet, cached for the request. */
    public function activeSnippetHtml(string $key): ?string
    {
        if (!array_key_exists($key, $this->snippetCache)) {
            $html = $this->db->selectValue('SELECT body_html FROM cms_snippets WHERE key_name = :k AND is_active = 1', ['k' => $key]);
            $this->snippetCache[$key] = $html === null ? null : (string) $html;
        }

        return $this->snippetCache[$key];
    }

    /** Pages (not in the trash) whose text uses {{snippet:key}}. @return list<array{public_id:string,title:string,path:string}> */
    public function pagesUsingSnippet(string $key): array
    {
        return $this->db->select(
            'SELECT public_id, title, path FROM cms_pages WHERE deleted_at IS NULL AND body_source LIKE :k ORDER BY title LIMIT 50',
            ['k' => '%{{snippet:' . addcslashes($key, '%_\\') . '}}%'],
        );
    }

    /** @param array<string,mixed> $data */
    public function insertSnippet(array $data): int
    {
        $this->snippetCache = [];

        return (int) $this->db->insertRow('cms_snippets', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateSnippet(int $id, array $data): void
    {
        $this->snippetCache = [];
        $this->update('cms_snippets', $id, $data);
    }

    public function deleteSnippet(int $id): void
    {
        $this->snippetCache = [];
        $this->db->affectingStatement('DELETE FROM cms_snippets WHERE id = :id', ['id' => $id]);
    }

    // ---- menus ----------------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> every item of a menu, in order */
    public function menu(string $menu): array
    {
        return $this->db->select('SELECT * FROM cms_menu_items WHERE menu = :m ORDER BY sort_order, id', ['m' => $menu]);
    }

    /**
     * The active items of a menu for the public layout, cached for the request. Never throws: a missing table (before the
     * migration) or a database hiccup just means "use the built-in links".
     *
     * @return list<array{label:string,url:string,new_tab:bool}>
     */
    public function publicMenu(string $menu): array
    {
        if (!isset($this->menuCache[$menu])) {
            try {
                $this->menuCache[$menu] = array_map(static fn (array $r): array => ['label' => (string) $r['label'], 'url' => (string) $r['url'], 'new_tab' => (bool) $r['new_tab']], $this->db->select(
                    'SELECT label, url, new_tab FROM cms_menu_items WHERE menu = :m AND is_active = 1 ORDER BY sort_order, id',
                    ['m' => $menu],
                ));
            } catch (\Throwable) {
                $this->menuCache[$menu] = [];
            }
        }

        return $this->menuCache[$menu];
    }

    /** @return array<string,mixed>|null */
    public function menuItem(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM cms_menu_items WHERE id = :id', ['id' => $id]);
    }

    public function menuCount(string $menu): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_menu_items WHERE menu = :m', ['m' => $menu], 0);
    }

    public function nextSortOrder(string $menu): int
    {
        return (int) $this->db->selectValue('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM cms_menu_items WHERE menu = :m', ['m' => $menu], 10);
    }

    /** @param array<string,mixed> $data */
    public function insertMenuItem(array $data): int
    {
        $this->menuCache = [];

        return (int) $this->db->insertRow('cms_menu_items', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateMenuItem(int $id, array $data): void
    {
        $this->menuCache = [];
        $this->update('cms_menu_items', $id, $data);
    }

    public function deleteMenuItem(int $id): void
    {
        $this->menuCache = [];
        $this->db->affectingStatement('DELETE FROM cms_menu_items WHERE id = :id', ['id' => $id]);
    }

    // ---- internals ------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function update(string $table, int $id, array $data): void
    {
        $sets = [];
        $bind = ['id' => $id];
        foreach ($data as $column => $value) {
            $sets[] = \App\Support\Sql::assign((string) $column, 'c_');
            $bind['c_' . $column] = $value;
        }
        $this->db->affectingStatement("UPDATE `{$table}` SET " . implode(', ', $sets) . ' WHERE id = :id', $bind);
    }
}
