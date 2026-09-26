<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\PermissionService;
use App\Cms\CmsFormatter;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Router;
use App\Models\User;
use App\Repositories\CmsSiteRepository;
use App\Support\Application;
use App\Support\Db;

/**
 * The site-wide CMS tools: redirects, reusable snippets and the public menus. All three change what every visitor sees, so
 * changing them needs `cms.publish`; every change is audited (module `cms`).
 *
 * Redirects: the old address is normalised (lowercase, one leading slash, no trailing slash, query and fragment dropped, your
 * own domain stripped if pasted in full). It may not be an address a route or a page already answers — a redirect there would
 * never fire. Targets are a site path or an https:// address. Loops and chains are refused, and when a new redirect makes older
 * ones point at an address that now moves on, those are re-pointed straight at the final target (no redirect chains).
 */
final class CmsSiteService
{
    public const CODES = [301 => '301 Moved permanently', 302 => '302 Found (temporary)', 307 => '307 Temporary (keeps method)', 308 => '308 Permanent (keeps method)', 410 => '410 Gone — removed for good'];
    public const MAX_REDIRECTS = 5000;
    public const MAX_IMPORT_LINES = 500;
    public const MAX_MENU_ITEMS = 12;
    public const MENUS = ['header' => 'Header menu', 'footer' => 'Footer links'];

    public function __construct(
        private readonly CmsSiteRepository $site,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
        private readonly Application $app,
        private readonly Db $db,
    ) {
    }

    // ---- redirects ------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $input from, to, code, note, keep_query, is_active
     * @return int the redirect id
     * @throws AuthorizationException|ValidationException
     */
    public function saveRedirect(array $input, User $actor, ?int $id = null): int
    {
        $this->assertPublish($actor);
        $existing = $id === null ? null : ($this->site->redirect($id) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That redirect no longer exists.', [], 404));
        $data = $this->validRedirect($input, $existing);

        return $this->db->transaction(function () use ($data, $existing, $actor): int {
            if ($existing === null) {
                if ($this->site->countRedirects() >= self::MAX_REDIRECTS) {
                    throw new ValidationException(['from' => ['There are already ' . self::MAX_REDIRECTS . ' redirects. Remove old ones first.']]);
                }
                $rid = $this->site->insertRedirect($data + ['created_by' => $actor->id]);
            } else {
                $rid = (int) $existing['id'];
                $this->site->updateRedirect($rid, $data);
            }
            $flattened = $data['to_url'] !== null ? $this->site->retarget($data['from_path'], $data['to_url'], $rid) : 0;
            $this->audit->log($existing === null ? 'cms_redirect_added' : 'cms_redirect_updated', 'cms', 'cms_redirect', $rid,
                $existing === null ? null : ['from' => $existing['from_path'], 'to' => $existing['to_url'], 'code' => (int) $existing['status_code']],
                ['from' => $data['from_path'], 'to' => $data['to_url'], 'code' => $data['status_code'], 'rechained' => $flattened], null, $actor);

            return $rid;
        });
    }

    /** @throws AuthorizationException */
    public function deleteRedirect(int $id, User $actor): bool
    {
        $this->assertPublish($actor);
        $row = $this->site->redirect($id);
        if ($row === null) {
            return false;
        }
        $this->site->deleteRedirect($id);
        $this->audit->log('cms_redirect_removed', 'cms', 'cms_redirect', $id, ['from' => $row['from_path'], 'to' => $row['to_url']], null, null, $actor);

        return true;
    }

    /** @throws AuthorizationException */
    public function toggleRedirect(int $id, User $actor): bool
    {
        $this->assertPublish($actor);
        $row = $this->site->redirect($id);
        if ($row === null) {
            return false;
        }
        $this->site->updateRedirect($id, ['is_active' => (int) $row['is_active'] === 1 ? 0 : 1]);
        $this->audit->log('cms_redirect_toggled', 'cms', 'cms_redirect', $id, ['active' => (int) $row['is_active']], ['active' => 1 - (int) $row['is_active']], null, $actor);

        return true;
    }

    /**
     * Many redirects at once, one per line: `old-address, new-address[, code]` (commas, tabs or semicolons). Lines that fail
     * are reported with the reason; the good ones are saved. A header line starting with "from" is skipped.
     *
     * @return array{added:int,updated:int,errors:list<string>}
     * @throws AuthorizationException|ValidationException
     */
    public function import(string $text, User $actor): array
    {
        $this->assertPublish($actor);
        $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $text))), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')));
        if ($lines === []) {
            throw new ValidationException(['import' => ['Paste at least one line: old-address, new-address[, code].']]);
        }
        if (count($lines) > self::MAX_IMPORT_LINES) {
            throw new ValidationException(['import' => ['At most ' . self::MAX_IMPORT_LINES . ' lines at a time.']]);
        }
        $out = ['added' => 0, 'updated' => 0, 'errors' => []];
        foreach ($lines as $n => $line) {
            if ($n === 0 && preg_match('/^from\b/i', $line) === 1) {
                continue;
            }
            $cells = array_map('trim', preg_split('/[,\t;]/', $line) ?: []);
            $input = ['from' => $cells[0] ?? '', 'to' => $cells[1] ?? '', 'code' => ($cells[2] ?? '') !== '' ? $cells[2] : '301', 'keep_query' => '1', 'is_active' => '1', 'note' => 'Imported'];
            $normalised = $this->normalizeFrom((string) $input['from']);
            $existing = $normalised === null ? null : $this->site->redirectFrom($normalised);
            try {
                $this->saveRedirect($input, $actor, $existing === null ? null : (int) $existing['id']);
                $existing === null ? $out['added']++ : $out['updated']++;
            } catch (ValidationException $e) {
                $out['errors'][] = 'Line ' . ($n + 1) . ' (' . mb_substr($line, 0, 60) . '): ' . (string) (array_values($e->errors())[0][0] ?? 'not valid');
            }
        }

        return $out;
    }

    /**
     * What the public site does for a path no route or page answered. Records the hit.
     *
     * @return array{url:?string,code:int}|null
     */
    public function resolve(string $path, string $query): ?array
    {
        $from = $this->normalizeFrom($path);
        if ($from === null) {
            return null;
        }
        $r = $this->site->activeRedirect($from);
        if ($r === null) {
            return null;
        }
        $this->site->recordHit((int) $r['id']);
        $url = $r['to_url'] === null ? null : (string) $r['to_url'];
        if ($url !== null && (bool) $r['keep_query'] && $query !== '' && !str_contains($url, '?')) {
            $url .= '?' . $query;
        }

        return ['url' => $url, 'code' => (int) $r['status_code']];
    }

    /** The normalised form of an old address, or null when it cannot be one. */
    public function normalizeFrom(string $raw): ?string
    {
        $raw = trim($raw);
        $own = parse_url((string) $this->app->config()->get('app.url', ''), PHP_URL_HOST);
        if (preg_match('#^https?://([^/]+)(/.*)?$#i', $raw, $m) === 1) {
            if (!is_string($own) || strcasecmp($m[1], $own) !== 0) {
                return null;   // only addresses on this site can be redirected from
            }
            $raw = $m[2] ?? '/';
        }
        $raw = (string) preg_replace('/[?#].*$/s', '', $raw);
        $raw = strtolower(rawurldecode($raw));
        $raw = '/' . trim($raw, '/');
        if ($raw === '/' || strlen($raw) > 300 || str_contains($raw, '//') || preg_match('#^/[a-z0-9._~\-/+%()!]+$#D', $raw) !== 1 || preg_match('#(^|/)\.\.?(/|$)#', $raw) === 1) {
            return null;
        }

        return $raw;
    }

    // ---- snippets -------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $input key, title, body, is_active
     * @throws AuthorizationException|ValidationException
     */
    public function saveSnippet(array $input, User $actor, ?string $key = null): string
    {
        $this->assertPublish($actor);
        $existing = $key === null ? null : ($this->site->snippet($key) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That snippet no longer exists.', [], 404));
        $errors = [];
        $newKey = $existing === null ? strtolower(trim((string) ($input['key'] ?? ''))) : (string) $existing['key_name'];
        if ($existing === null) {
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $newKey) !== 1 || strlen($newKey) > 60) {
                $errors['key'] = ['The key may use lowercase letters, numbers and single hyphens (60 characters at most).'];
            } elseif ($this->site->snippet($newKey) !== null) {
                $errors['key'] = ['Another snippet already uses this key.'];
            }
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = ['Give the snippet a name of up to 120 characters.'];
        }
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['body'] ?? '')));
        if ($body === '' || mb_strlen($body) > 20000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body) === 1) {
            $errors['body'] = ['Write the snippet (20,000 characters at most, no control characters).'];
        } elseif (preg_match('/\{\{\s*snippet\s*:/', $body) === 1) {
            $errors['body'] = ['A snippet cannot include another snippet.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $data = ['title' => $title, 'body_source' => $body, 'body_html' => CmsFormatter::toHtml($body), 'is_active' => !empty($input['is_active']) ? 1 : 0, 'updated_by' => $actor->id];
        if ($existing === null) {
            $id = $this->site->insertSnippet($data + ['key_name' => $newKey]);
            $this->audit->log('cms_snippet_added', 'cms', 'cms_snippet', $id, null, ['key' => $newKey], null, $actor);
        } else {
            $this->site->updateSnippet((int) $existing['id'], $data);
            $this->audit->log('cms_snippet_updated', 'cms', 'cms_snippet', (int) $existing['id'], ['active' => (int) $existing['is_active']], ['key' => $newKey, 'active' => $data['is_active']], null, $actor);
        }

        return $newKey;
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function deleteSnippet(string $key, User $actor): void
    {
        $this->assertPublish($actor);
        $row = $this->site->snippet($key) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That snippet no longer exists.', [], 404);
        $used = $this->site->pagesUsingSnippet($key);
        if ($used !== []) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Still used on ' . count($used) . ' page(s) (' . implode(', ', array_map(static fn (array $p): string => '/' . $p['path'], array_slice($used, 0, 3))) . '). Remove it there first, or switch it off.', [], 422);
        }
        $this->site->deleteSnippet((int) $row['id']);
        $this->audit->log('cms_snippet_removed', 'cms', 'cms_snippet', (int) $row['id'], ['key' => $key], null, null, $actor);
    }

    // ---- menus ----------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $input menu, label, url, new_tab, is_active
     * @throws AuthorizationException|ValidationException|DomainRuleException
     */
    public function saveMenuItem(array $input, User $actor, ?int $id = null): int
    {
        $this->assertPublish($actor);
        $existing = $id === null ? null : ($this->site->menuItem($id) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That menu item no longer exists.', [], 404));
        $menu = $existing === null ? (string) ($input['menu'] ?? '') : (string) $existing['menu'];
        $errors = [];
        if (!isset(self::MENUS[$menu])) {
            $errors['menu'] = ['Choose a menu.'];
        }
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 60 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            $errors['label'] = ['The label is one line of up to 60 characters.'];
        }
        $url = trim((string) ($input['url'] ?? ''));
        if (!$this->validMenuUrl($url)) {
            $errors['url'] = ['Use a site path (/about), a https:// address, #anchor, mailto: or tel:.'];
        }
        if ($errors === [] && $existing === null && $this->site->menuCount($menu) >= self::MAX_MENU_ITEMS) {
            $errors['menu'] = ['A menu holds at most ' . self::MAX_MENU_ITEMS . ' links — keep it short.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $data = ['label' => $label, 'url' => $url, 'new_tab' => !empty($input['new_tab']) ? 1 : 0, 'is_active' => !empty($input['is_active']) ? 1 : 0];
        if ($existing === null) {
            $id = $this->site->insertMenuItem($data + ['menu' => $menu, 'sort_order' => $this->site->nextSortOrder($menu)]);
            $this->audit->log('cms_menu_item_added', 'cms', 'cms_menu_item', $id, null, ['menu' => $menu, 'label' => $label, 'url' => $url], null, $actor);
        } else {
            $this->site->updateMenuItem((int) $existing['id'], $data);
            $this->audit->log('cms_menu_item_updated', 'cms', 'cms_menu_item', (int) $existing['id'], ['label' => $existing['label'], 'url' => $existing['url']], ['label' => $label, 'url' => $url], null, $actor);
        }

        return (int) $id;
    }

    /** @throws AuthorizationException */
    public function deleteMenuItem(int $id, User $actor): bool
    {
        $this->assertPublish($actor);
        $row = $this->site->menuItem($id);
        if ($row === null) {
            return false;
        }
        $this->site->deleteMenuItem($id);
        $this->audit->log('cms_menu_item_removed', 'cms', 'cms_menu_item', $id, ['menu' => $row['menu'], 'label' => $row['label']], null, null, $actor);

        return true;
    }

    /** Swap an item with its neighbour above (-1) or below (+1). @throws AuthorizationException */
    public function moveMenuItem(int $id, int $direction, User $actor): bool
    {
        $this->assertPublish($actor);
        $row = $this->site->menuItem($id);
        if ($row === null || !in_array($direction, [-1, 1], true)) {
            return false;
        }
        $items = $this->site->menu((string) $row['menu']);
        $ids = array_map(static fn (array $i): int => (int) $i['id'], $items);
        $pos = array_search($id, $ids, true);
        $other = $pos === false ? null : ($items[$pos + $direction] ?? null);
        if ($other === null) {
            return false;
        }
        // renumber so equal sort orders can never make a swap a no-op
        $order = $ids;
        [$order[$pos], $order[$pos + $direction]] = [$order[$pos + $direction], $order[$pos]];
        $this->db->transaction(function () use ($order): void {
            foreach ($order as $i => $itemId) {
                $this->site->updateMenuItem($itemId, ['sort_order' => ($i + 1) * 10]);
            }
        });
        $this->audit->log('cms_menu_reordered', 'cms', 'cms_menu_item', $id, null, ['menu' => $row['menu'], 'direction' => $direction > 0 ? 'down' : 'up'], null, $actor);

        return true;
    }

    // ---- rules ------------------------------------------------------------------------------------------------

    /** @throws AuthorizationException */
    private function assertPublish(User $u): void
    {
        if (!$this->permissions->userCan($u, 'cms.publish')) {
            throw AuthorizationException::forPermission('cms.publish');
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{from_path:string,to_url:?string,status_code:int,keep_query:int,is_active:int,note:?string}
     * @throws ValidationException
     */
    private function validRedirect(array $input, ?array $existing): array
    {
        $errors = [];
        $from = $this->normalizeFrom((string) ($input['from'] ?? ''));
        $code = (int) ($input['code'] ?? 301);
        if (!isset(self::CODES[$code])) {
            $errors['code'] = ['Choose one of the redirect types.'];
        }
        if ($from === null) {
            $errors['from'] = ['Enter the old address on this site, e.g. /old-page.html (not the home page).'];
        } else {
            if ($this->routeAnswers($from)) {
                $errors['from'] = ['A screen of this site already answers at ' . $from . ' — a redirect there would never be used.'];
            } elseif ($this->db->exists('SELECT 1 FROM cms_pages WHERE path = :p AND deleted_at IS NULL', ['p' => ltrim($from, '/')])) {
                $errors['from'] = ['A page uses ' . $from . '. Trash or unpublish it first, or edit the page instead.'];
            } else {
                $clash = $this->site->redirectFrom($from);
                if ($clash !== null && (int) $clash['id'] !== (int) ($existing['id'] ?? 0)) {
                    $errors['from'] = ['There is already a redirect from ' . $from . '.'];
                }
            }
        }

        $to = trim((string) ($input['to'] ?? ''));
        if ($code === 410) {
            $to = '';
        } elseif ($to === '') {
            $errors['to'] = ['Enter where visitors should go.'];
        } elseif (preg_match('#^(?:https://[^\s<>"\']{4,490}|/(?!/)[^\s<>"\']{0,490})$#D', $to) !== 1) {
            $errors['to'] = ['The new address is a site path (/new-page) or a full https:// address.'];
        } elseif ($from !== null && isset(self::CODES[$code])) {
            $target = $this->internalPath($to);
            if ($target !== null && $target === $from) {
                $errors['to'] = ['A redirect cannot point to itself.'];
            } elseif ($target !== null && ($chain = $this->site->activeRedirect($target)) !== null && (int) $chain['id'] !== (int) ($existing['id'] ?? 0)) {
                $errors['to'] = [$target . ' itself redirects to ' . ($chain['to_url'] ?? '(gone)') . ' — point straight at the final address.'];
            }
        }
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 200) {
            $errors['note'] = ['The note is too long (200 characters at most).'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        \assert($from !== null);

        return ['from_path' => $from, 'to_url' => $to === '' ? null : $to, 'status_code' => $code, 'keep_query' => !empty($input['keep_query']) ? 1 : 0, 'is_active' => !array_key_exists('is_active', $input) || !empty($input['is_active']) ? 1 : 0, 'note' => $note === '' ? null : $note];
    }

    /** The normalised path of a target on this site (a site path, or a full URL on our own domain), else null. */
    private function internalPath(string $to): ?string
    {
        if (str_starts_with($to, '/')) {
            return $this->normalizeFrom($to);
        }
        $own = parse_url((string) $this->app->config()->get('app.url', ''), PHP_URL_HOST);
        $host = parse_url($to, PHP_URL_HOST);

        return is_string($own) && is_string($host) && strcasecmp($own, $host) === 0 ? $this->normalizeFrom($to) : null;
    }

    private function routeAnswers(string $path): bool
    {
        if (!$this->app->bound(Router::class)) {
            return false;
        }
        foreach ($this->app->get(Router::class)->routes() as $route) {
            if (in_array('GET', $route->methods, true) && $route->matchesPathOnly($path)) {
                return true;
            }
        }

        return false;
    }

    private function validMenuUrl(string $url): bool
    {
        return strlen($url) <= 300 && preg_match('#^(?:/(?!/)[^\s<>"\']*|https://[^\s<>"\']{4,}|\#[a-z0-9-]{1,80}|mailto:[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,120}\.[A-Za-z]{2,24}|tel:\+?[0-9]{5,15})$#D', $url) === 1;
    }
}
