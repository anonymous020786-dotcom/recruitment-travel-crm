<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\CmsSiteRepository;
use App\Services\CmsSiteService;

/** Admin → Pages → Redirects / Snippets / Menus. The rules live in CmsSiteService; this class translates. */
final class CmsSiteController extends CrmController
{
    public function __construct(
        private readonly CmsSiteRepository $site,
        private readonly CmsSiteService $service,
    ) {
    }

    // ---- redirects ------------------------------------------------------------------------------------------

    public function redirects(Request $request): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $page = max(1, min((int) $request->query('page', '1'), 1000));
        $result = $this->site->redirects($q, $page);
        $editId = (int) $request->query('edit', '0');

        return view_response('crm.admin.cms.redirects', [
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => 50, 'q' => $q,
            'edit' => $editId > 0 ? $this->site->redirect($editId) : null,
            'codes' => CmsSiteService::CODES, 'canPublish' => can('cms.publish'),
            'importResult' => session()?->get('import_result'),
        ]);
    }

    public function storeRedirect(Request $request): Response
    {
        return $this->saveRedirect($request, null);
    }

    public function updateRedirect(Request $request, string $id): Response
    {
        return $this->saveRedirect($request, $this->id($id));
    }

    public function deleteRedirect(string $id): Response
    {
        $done = $this->service->deleteRedirect($this->id($id), $this->currentUser());
        flash('status', $done ? 'Redirect removed.' : 'That redirect was already gone.');

        return Response::redirect('/admin/cms/redirects');
    }

    public function toggleRedirect(string $id): Response
    {
        $this->service->toggleRedirect($this->id($id), $this->currentUser()) || abort(404);
        flash('status', 'Redirect switched.');

        return Response::redirect('/admin/cms/redirects');
    }

    public function importRedirects(Request $request): Response
    {
        try {
            $r = $this->service->import((string) $request->input('import', ''), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), ['import' => mb_substr((string) $request->input('import', ''), 0, 20000)], '/admin/cms/redirects');
        }
        flash('status', "Imported: {$r['added']} added, {$r['updated']} updated" . ($r['errors'] === [] ? '.' : ', ' . count($r['errors']) . ' line(s) skipped.'));
        flash('import_result', array_slice($r['errors'], 0, 50));

        return Response::redirect('/admin/cms/redirects');
    }

    // ---- snippets -------------------------------------------------------------------------------------------

    public function snippets(): Response
    {
        return view_response('crm.admin.cms.snippets', ['rows' => $this->site->snippets(), 'canPublish' => can('cms.publish')]);
    }

    public function createSnippet(): Response
    {
        return view_response('crm.admin.cms.snippet-form', ['s' => null, 'usedOn' => [], 'canPublish' => can('cms.publish')]);
    }

    public function storeSnippet(Request $request): Response
    {
        try {
            $key = $this->service->saveSnippet($request->only(['key', 'title', 'body', 'is_active']), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(['key', 'title', 'body', 'is_active']), '/admin/cms/snippets/create');
        }
        flash('status', 'Snippet saved. Insert it on a page with {{snippet:' . $key . '}}.');

        return Response::redirect('/admin/cms/snippets/' . $key . '/edit');
    }

    public function editSnippet(string $key): Response
    {
        $s = $this->site->snippet($key) ?? abort(404, 'Snippet not found.');

        return view_response('crm.admin.cms.snippet-form', ['s' => $s, 'usedOn' => $this->site->pagesUsingSnippet($key), 'canPublish' => can('cms.publish')]);
    }

    public function updateSnippet(Request $request, string $key): Response
    {
        $this->site->snippet($key) ?? abort(404, 'Snippet not found.');
        try {
            $this->service->saveSnippet($request->only(['title', 'body', 'is_active']), $this->currentUser(), $key);
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(['title', 'body', 'is_active']), '/admin/cms/snippets/' . $key . '/edit');
        }
        flash('status', 'Snippet saved. Every page that uses it shows the new version.');

        return Response::redirect('/admin/cms/snippets/' . $key . '/edit');
    }

    public function deleteSnippet(string $key): Response
    {
        try {
            $this->service->deleteSnippet($key, $this->currentUser());
        } catch (DomainRuleException $e) {
            if ($e->httpStatus() === 404) {
                abort(404);
            }

            return redirect_with_errors(['form' => [$e->getMessage()]], [], '/admin/cms/snippets/' . $key . '/edit');
        }
        flash('status', 'Snippet deleted.');

        return Response::redirect('/admin/cms/snippets');
    }

    // ---- menus ----------------------------------------------------------------------------------------------

    public function menus(): Response
    {
        $menus = [];
        foreach (array_keys(CmsSiteService::MENUS) as $m) {
            $menus[$m] = $this->site->menu($m);
        }

        return view_response('crm.admin.cms.menus', ['menus' => $menus, 'labels' => CmsSiteService::MENUS, 'max' => CmsSiteService::MAX_MENU_ITEMS, 'canPublish' => can('cms.publish')]);
    }

    public function storeMenuItem(Request $request): Response
    {
        try {
            $this->service->saveMenuItem($request->only(['menu', 'label', 'url', 'new_tab', 'is_active']), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(['menu', 'label', 'url', 'new_tab', 'is_active']), '/admin/cms/menus');
        }
        flash('status', 'Link added.');

        return Response::redirect('/admin/cms/menus');
    }

    public function updateMenuItem(Request $request, string $id): Response
    {
        try {
            $this->service->saveMenuItem($request->only(['label', 'url', 'new_tab', 'is_active']), $this->currentUser(), $this->id($id));
        } catch (ValidationException $e) {
            return redirect_with_errors(['item_' . $id => array_merge(...array_values($e->errors()))], [], '/admin/cms/menus');
        } catch (DomainRuleException) {
            abort(404);
        }
        flash('status', 'Link saved.');

        return Response::redirect('/admin/cms/menus');
    }

    public function deleteMenuItem(string $id): Response
    {
        $this->service->deleteMenuItem($this->id($id), $this->currentUser());
        flash('status', 'Link removed.');

        return Response::redirect('/admin/cms/menus');
    }

    public function moveMenuItem(Request $request, string $id): Response
    {
        $this->service->moveMenuItem($this->id($id), (string) $request->input('dir', '') === 'up' ? -1 : 1, $this->currentUser());

        return Response::redirect('/admin/cms/menus');
    }

    // ---- internals ------------------------------------------------------------------------------------------

    private function saveRedirect(Request $request, ?int $id): Response
    {
        $fields = ['from', 'to', 'code', 'note', 'keep_query', 'is_active'];
        try {
            $this->service->saveRedirect($request->only($fields), $this->currentUser(), $id);
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only($fields), '/admin/cms/redirects' . ($id !== null ? '?edit=' . $id : ''));
        } catch (DomainRuleException) {
            abort(404);
        }
        flash('status', $id === null ? 'Redirect added. It works immediately.' : 'Redirect saved.');

        return Response::redirect('/admin/cms/redirects');
    }

    private function id(string $raw): int
    {
        return preg_match('/^\d{1,18}$/D', $raw) === 1 ? (int) $raw : abort(404);
    }
}
