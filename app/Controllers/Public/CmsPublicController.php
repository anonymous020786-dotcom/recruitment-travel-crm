<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Cms\CmsRenderer;
use App\Controllers\Controller;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\CmsPageRepository;
use App\Services\CmsPageService;
use App\Services\CmsSiteService;

/**
 * Website pages on the public site. `page()` is the router's fallback: it runs only for a GET that no route matched, so a
 * page can never shadow a real route (ReservedPaths also refuses such an address when the page is saved). Only a live page is
 * ever returned (see CmsPageRepository::LIVE); a preview link shows an unpublished page, uncached and hidden from search engines.
 */
final class CmsPublicController extends Controller
{
    private const PATH = '#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*){0,2}$#D';

    public function __construct(
        private readonly CmsPageRepository $pages,
        private readonly CmsPageService $service,
        private readonly CmsRenderer $renderer,
        private readonly CmsSiteService $site,
    ) {
    }

    public function page(Request $request): Response
    {
        // a redirect set up in Admin → Pages → Redirects wins over a page (the editor refuses to create one where a page lives)
        $redirect = $this->site->resolve($request->path(), (string) parse_url($request->fullUrl(), PHP_URL_QUERY));
        if ($redirect !== null) {
            if ($redirect['url'] === null) {
                return view_response('public.cms.gone', [], 410)->withHeader('Cache-Control', 'public, max-age=3600');
            }

            return Response::redirect($redirect['url'], $redirect['code'], allowExternal: true)
                ->withHeader('Cache-Control', in_array($redirect['code'], [301, 308], true) ? 'public, max-age=3600' : 'no-store');
        }

        $path = trim($request->path(), '/');
        if ($path === '' || strlen($path) > 150 || preg_match(self::PATH, $path) !== 1) {
            throw HttpException::notFound('No page here.');
        }
        $page = $this->pages->live($path);
        if ($page === null) {
            throw HttpException::notFound('No page here.');
        }

        $response = view_response('public.cms.page', ['page' => $page, 'renderer' => $this->renderer, 'preview' => false]);
        $etag = '"' . md5($response->getBody()) . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300'];
        if ($request->header('If-None-Match') === $etag) {
            return Response::make('', 304, $headers);
        }

        return $response->withHeader('ETag', $etag)->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function preview(string $token): Response
    {
        $page = $this->service->pageForPreview($token);
        if ($page === null) {
            throw HttpException::notFound('This preview link is not valid any more.');
        }

        return view_response('public.cms.page', ['page' => $page, 'renderer' => $this->renderer, 'preview' => true])
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
