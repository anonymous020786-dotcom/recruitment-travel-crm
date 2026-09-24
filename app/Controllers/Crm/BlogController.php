<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\BlogRepository;
use App\Services\BlogService;

/** Admin → Blog: write, preview, publish and archive posts. The rules live in BlogService. */
final class BlogController extends CrmController
{
    private const FIELDS = ['title', 'slug', 'excerpt', 'body'];
    private const PER_PAGE = 20;

    public function __construct(
        private readonly BlogRepository $posts,
        private readonly BlogService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', '');
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $page = max(1, min((int) $request->query('page', '1'), 1000));
        $result = $this->posts->adminPage($status, $search, $page, self::PER_PAGE);

        return view_response('crm.admin.blog.index', [
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'status' => in_array($status, BlogRepository::STATUSES, true) ? $status : '', 'search' => $search, 'counts' => $this->posts->counts(),
        ]);
    }

    public function create(): Response
    {
        return view_response('crm.admin.blog.form', ['post' => null]);
    }

    public function store(Request $request): Response
    {
        try {
            $publicId = $this->service->create($request->only(self::FIELDS), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(self::FIELDS), '/admin/blog/create');
        }
        flash('status', 'Draft saved. Preview it below, then publish when it is ready.');

        return Response::redirect('/admin/blog/' . $publicId . '/edit');
    }

    public function edit(string $post): Response
    {
        return view_response('crm.admin.blog.form', ['post' => $this->find($post)]);
    }

    public function update(Request $request, string $post): Response
    {
        $row = $this->find($post);
        try {
            $this->service->update($post, $request->only(self::FIELDS), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(self::FIELDS), '/admin/blog/' . $row['public_id'] . '/edit');
        } catch (DomainRuleException $e) {
            return redirect_with_errors(['form' => [$e->getMessage()]], $request->only(self::FIELDS), '/admin/blog/' . $row['public_id'] . '/edit');
        }
        flash('status', 'Changes saved.');

        return Response::redirect('/admin/blog/' . $row['public_id'] . '/edit');
    }

    public function publish(string $post): Response
    {
        return $this->move($post, fn () => $this->service->publish($post, $this->currentUser()), 'Published. It is live on the public blog now.');
    }

    public function unpublish(string $post): Response
    {
        return $this->move($post, fn () => $this->service->unpublish($post, $this->currentUser()), 'Moved back to draft. It is no longer public.');
    }

    public function archive(string $post): Response
    {
        return $this->move($post, fn () => $this->service->archive($post, $this->currentUser()), 'Archived. It is no longer public.');
    }

    // ---- internals -----------------------------------------------------------------------------

    /** @param callable():void $do */
    private function move(string $post, callable $do, string $success): Response
    {
        $row = $this->find($post);
        try {
            $do();
            flash('status', $success);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/admin/blog/' . $row['public_id'] . '/edit');
    }

    /** @return array<string,mixed> */
    private function find(string $publicId): array
    {
        $row = $this->posts->find($publicId);
        if ($row === null) {
            abort(404, 'Post not found.');
        }

        return $row;
    }
}
