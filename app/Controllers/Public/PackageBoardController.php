<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\PublicCatalogRepository;
use App\Services\PublicEnquiryService;

/** Public travel packages: cacheable list and detail pages, and a separate enquiry form page (needs a session). */
final class PackageBoardController extends Controller
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly PublicCatalogRepository $catalog,
        private readonly PublicEnquiryService $enquiries,
    ) {
    }

    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $page = max(1, min((int) $request->query('page', '1'), 500));
        $result = $this->catalog->packages($search, $page, self::PER_PAGE);

        return view_response('public.packages.index', [
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'search' => $search,
        ])->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function show(string $slug): Response
    {
        $pkg = $this->catalog->package($slug);
        if ($pkg === null) {
            abort(404, 'This package is no longer available.');
        }

        return view_response('public.packages.show', ['pkg' => $pkg, 'slug' => $slug])->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function enquireForm(string $slug): Response
    {
        $pkg = $this->catalog->package($slug);
        if ($pkg === null) {
            abort(404, 'This package is no longer available.');
        }

        return view_response('public.packages.enquire', ['pkg' => $pkg, 'slug' => $slug])->withHeader('Cache-Control', 'private, max-age=0');
    }

    public function enquire(Request $request, string $slug): Response
    {
        $id = $this->catalog->packageId($slug);
        if ($id === null) {
            abort(404, 'This package is no longer available.');
        }

        return $this->enquiries->submit($request, 'travel_enquiry', '/travel-packages/' . rawurlencode($slug) . '/enquire', packageId: $id, meta: ['package_slug' => $slug]);
    }
}
