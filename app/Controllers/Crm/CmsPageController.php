<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Cms\CmsFormatter;
use App\Cms\CmsRenderer;
use App\Cms\LineDiff;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\CmsPageRepository;
use App\Services\CmsPageService;

/** Admin → Pages: the list, the editor and every workflow action. The rules live in CmsPageService; this class translates. */
final class CmsPageController extends CrmController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly CmsPageRepository $pages,
        private readonly CmsPageService $service,
        private readonly CmsRenderer $renderer,
    ) {
    }

    // ---- list -----------------------------------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $tab = (string) $request->query('tab', 'all');
        $tab = in_array($tab, CmsPageRepository::TABS, true) ? $tab : 'all';
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $page = max(1, min((int) $request->query('page', '1'), 500));
        $result = $this->pages->adminPage($tab, $q, $page, self::PER_PAGE);

        return view_response('crm.admin.cms.index', [
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'tab' => $tab, 'q' => $q, 'counts' => $this->pages->counts(),
            'canManage' => can('cms.manage'), 'canPublish' => can('cms.publish'),
        ]);
    }

    // ---- create / edit --------------------------------------------------------------------------------------

    public function create(): Response
    {
        return view_response('crm.admin.cms.form', $this->formData(null));
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->service->create($this->input($request), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $this->old($request), '/admin/cms/create');
        }
        flash('status', 'Page created as a draft.');

        return Response::redirect('/admin/cms/' . $id . '/edit');
    }

    public function edit(string $page): Response
    {
        return view_response('crm.admin.cms.form', $this->formData($this->find($page)))->withHeader('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $page): Response
    {
        $row = $this->find($page);
        $back = '/admin/cms/' . $row['public_id'] . '/edit';
        try {
            $changed = $this->service->update($page, $this->input($request), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $this->old($request), $back);
        } catch (DomainRuleException $e) {
            return redirect_with_errors(['form' => [$e->getMessage()]], $this->old($request), $back);
        }
        flash('status', $changed === [] ? 'Nothing changed.' : 'Saved (version ' . ((int) $row['version'] + 1) . ').');

        return Response::redirect($back);
    }

    // ---- workflow -------------------------------------------------------------------------------------------

    public function submit(string $page): Response
    {
        return $this->act($page, fn () => $this->service->submitForReview($page, $this->currentUser()), 'Submitted for review. The publishers were notified.');
    }

    public function sendBack(string $page): Response
    {
        return $this->act($page, fn () => $this->service->sendBack($page, $this->currentUser()), 'Sent back to draft.');
    }

    public function publish(Request $request, string $page): Response
    {
        $when = trim((string) $request->input('publish_at', ''));

        return $this->act($page, fn () => $this->service->publish($page, $this->currentUser(), $when), $when === '' ? 'Published — it is live now.' : 'Scheduled to go live at the chosen time.');
    }

    public function unpublish(string $page): Response
    {
        return $this->act($page, fn () => $this->service->unpublish($page, $this->currentUser()), 'Unpublished — it is a draft again and no longer public.');
    }

    public function archive(string $page): Response
    {
        return $this->act($page, fn () => $this->service->archive($page, $this->currentUser()), 'Archived.');
    }

    public function restore(string $page): Response
    {
        return $this->act($page, fn () => $this->service->restoreArchived($page, $this->currentUser()), 'Restored to a draft.');
    }

    public function duplicate(string $page): Response
    {
        $this->find($page);
        try {
            $new = $this->service->duplicate($page, $this->currentUser());
        } catch (DomainRuleException $e) {
            return $this->refused($e, '/admin/cms');
        }
        flash('status', 'Copied as a new draft.');

        return Response::redirect('/admin/cms/' . $new . '/edit');
    }

    public function trash(string $page): Response
    {
        $this->find($page);
        try {
            $this->service->trash($page, $this->currentUser());
        } catch (DomainRuleException $e) {
            return $this->refused($e, '/admin/cms');
        }
        flash('status', 'Moved to the trash. It is deleted for good after 30 days.');

        return Response::redirect('/admin/cms');
    }

    public function untrash(string $page): Response
    {
        return $this->act($page, fn () => $this->service->restoreFromTrash($page, $this->currentUser()), 'Restored from the trash.');
    }

    public function purge(string $page): Response
    {
        $this->find($page);
        try {
            $this->service->purge($page, $this->currentUser());
        } catch (DomainRuleException $e) {
            return $this->refused($e, '/admin/cms?tab=trash');
        }
        flash('status', 'Deleted for good.');

        return Response::redirect('/admin/cms?tab=trash');
    }

    public function bulk(Request $request): Response
    {
        $ids = $request->input('ids', []);
        try {
            $r = $this->service->bulk((string) $request->input('action', ''), is_array($ids) ? array_values($ids) : [], $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], '/admin/cms');
        }
        flash('status', $r['done'] . ' page' . ($r['done'] === 1 ? '' : 's') . ' updated' . ($r['skipped'] === [] ? '.' : '; ' . count($r['skipped']) . ' skipped.'));
        if ($r['skipped'] !== []) {
            session()?->flash('error_toast', implode(' · ', array_slice($r['skipped'], 0, 3)) . (count($r['skipped']) > 3 ? ' …' : ''));
        }

        $tab = (string) $request->input('tab', '');

        return Response::redirect(in_array($tab, CmsPageRepository::TABS, true) && $tab !== 'all' ? '/admin/cms?tab=' . $tab : '/admin/cms');
    }

    // ---- revisions ------------------------------------------------------------------------------------------

    public function revisions(Request $request, string $page): Response
    {
        $row = $this->find($page);
        $revs = $this->pages->revisions((int) $row['id']);
        $versions = array_map(static fn (array $r): int => (int) $r['version'], $revs);
        $a = (int) $request->query('a', '0');
        $b = (int) $request->query('b', '0');
        $diff = null;
        if ($a > 0 && $b > 0 && in_array($a, $versions, true) && in_array($b, [...$versions, 0], true)) {
            try {
                $diff = $this->service->compare($page, $a, $b);
            } catch (DomainRuleException) {
                $diff = null;
            }
        }

        return view_response('crm.admin.cms.revisions', [
            'page' => $row, 'revisions' => $revs, 'a' => $a, 'b' => $b, 'diff' => $diff, 'stats' => $diff === null ? null : LineDiff::stats($diff),
            'canManage' => can('cms.manage'),
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    public function revertRevision(string $page, string $version): Response
    {
        $row = $this->find($page);
        try {
            $this->service->restoreRevision($page, (int) $version, $this->currentUser());
        } catch (DomainRuleException $e) {
            return $this->refused($e, '/admin/cms/' . $row['public_id'] . '/revisions');
        }
        flash('status', 'Version ' . (int) $version . ' was put back as the newest version.');

        return Response::redirect('/admin/cms/' . $row['public_id'] . '/edit');
    }

    // ---- preview --------------------------------------------------------------------------------------------

    /** The saved page exactly as visitors would see it, whatever its status. */
    public function preview(string $page): Response
    {
        $row = $this->find($page);

        return view_response('public.cms.page', ['page' => $row, 'renderer' => $this->renderer, 'preview' => true])
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function createPreviewLink(string $page): Response
    {
        $row = $this->find($page);
        try {
            $token = $this->service->createPreviewLink($page, $this->currentUser());
        } catch (DomainRuleException $e) {
            return $this->refused($e, '/admin/cms/' . $row['public_id'] . '/edit');
        }
        // shown once, on the next page, and never stored in the clear
        flash('preview_url', rtrim((string) config('app.url', ''), '/') . '/preview/' . $token);
        flash('status', 'Preview link created. It works for ' . CmsPageService::PREVIEW_DAYS . ' days — copy it now, it is not shown again.');

        return Response::redirect('/admin/cms/' . $row['public_id'] . '/edit');
    }

    public function revokePreviewLinks(string $page): Response
    {
        $row = $this->find($page);
        try {
            $n = $this->service->revokePreviewLinks($page, $this->currentUser());
        } catch (DomainRuleException $e) {
            return $this->refused($e, '/admin/cms/' . $row['public_id'] . '/edit');
        }
        flash('status', $n > 0 ? 'Every preview link was revoked.' : 'There were no preview links.');

        return Response::redirect('/admin/cms/' . $row['public_id'] . '/edit');
    }

    // ---- internals ------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function find(string $publicId): array
    {
        return $this->pages->find($publicId) ?? abort(404, 'Page not found.');
    }

    /** @param callable():void $do */
    private function act(string $page, callable $do, string $success): Response
    {
        $row = $this->find($page);
        $back = '/admin/cms/' . $row['public_id'] . '/edit';
        try {
            $do();
            flash('status', $success);
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], $back);
        } catch (DomainRuleException $e) {
            return $this->refused($e, $back);
        }

        return Response::redirect($back);
    }

    private function refused(DomainRuleException $e, string $back): Response
    {
        if ($e->httpStatus() === 404) {
            abort(404, $e->getMessage());
        }

        return redirect_with_errors(['form' => [$e->getMessage()]], [], $back);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        return $request->only([
            'title', 'path', 'summary', 'body', 'template', 'meta_title', 'meta_description', 'focus_keyword', 'canonical_url', 'robots',
            'og_title', 'og_description', 'featured_image', 'featured_alt', 'in_sitemap', 'sitemap_priority', 'sitemap_changefreq',
            'faq_q', 'faq_a', 'publish_at', 'unpublish_at', 'version',
        ]);
    }

    /** @return array<string,mixed> what to refill the form with after a validation error */
    private function old(Request $request): array
    {
        return $this->input($request);
    }

    /** @param array<string,mixed>|null $page @return array<string,mixed> */
    private function formData(?array $page): array
    {
        $user = $this->currentUser();
        $data = [
            'p' => $page, 'canManage' => can('cms.manage'), 'canPublish' => can('cms.publish'),
            'templates' => CmsPageService::TEMPLATES, 'tz' => (string) config('app.timezone', 'UTC'),
        ];
        if ($page !== null) {
            $data['seo'] = $this->service->seo($page);
            $data['previewLinks'] = $this->pages->activePreviewTokens((int) $page['id']);
            $data['revisionCount'] = count($this->pages->revisions((int) $page['id']));
            $data['words'] = CmsFormatter::wordCount((string) $page['body_html']);
            $data['editable'] = $this->service->mayManage($user) && $page['deleted_at'] === null && $page['status'] !== 'archived' && ($page['status'] !== 'published' || $this->service->mayPublish($user));
        }

        return $data;
    }
}
