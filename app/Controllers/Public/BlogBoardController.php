<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\BlogRepository;

/** The public blog: a cacheable list and article pages. Only published posts are ever reachable (see BlogRepository). */
final class BlogBoardController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(private readonly BlogRepository $posts)
    {
    }

    public function index(Request $request): Response
    {
        $page = max(1, min((int) $request->query('page', '1'), 500));
        $result = $this->posts->published($page, self::PER_PAGE);

        return view_response('public.blog.index', [
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
        ])->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function show(string $slug): Response
    {
        $post = $this->posts->publishedBySlug($slug);
        if ($post === null) {
            abort(404, 'This article is not available.');
        }

        return view_response('public.blog.show', ['post' => $post])->withHeader('Cache-Control', 'public, max-age=300');
    }
}
