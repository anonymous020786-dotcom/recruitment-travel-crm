<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\PublicCatalogRepository;
use App\Services\PublicEnquiryService;

/**
 * The public jobs board. List and detail pages are session-free and cacheable (crawlers get them cheaply); the apply
 * form lives on its own page because it carries a CSRF token and therefore needs a session.
 */
final class JobBoardController extends Controller
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly PublicCatalogRepository $catalog,
        private readonly PublicEnquiryService $enquiries,
    ) {
    }

    public function index(Request $request): Response
    {
        $country = strtoupper(trim((string) $request->query('country', '')));
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $page = max(1, min((int) $request->query('page', '1'), 500));

        $result = $this->catalog->jobs($country, $search, $page, self::PER_PAGE);
        $countries = $this->catalog->jobCountries();
        $known = array_column($countries, 'name', 'code');

        return view_response('public.jobs.index', [
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'country' => isset($known[$country]) ? $country : '', 'countryName' => $known[$country] ?? null,
            'search' => $search, 'countries' => $countries,
        ])->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function show(string $slug): Response
    {
        $job = $this->catalog->job($slug);
        if ($job === null) {
            abort(404, 'This vacancy is no longer available.');
        }

        return view_response('public.jobs.show', ['job' => $job, 'slug' => $slug])->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function applyForm(string $slug): Response
    {
        $job = $this->catalog->job($slug);
        if ($job === null) {
            abort(404, 'This vacancy is no longer available.');
        }

        return view_response('public.jobs.apply', ['job' => $job, 'slug' => $slug])->withHeader('Cache-Control', 'private, max-age=0');
    }

    public function apply(Request $request, string $slug): Response
    {
        $jobId = $this->catalog->jobId($slug);
        if ($jobId === null) {
            abort(404, 'This vacancy is no longer available.');
        }

        return $this->enquiries->submit($request, 'job_apply', '/overseas-jobs/' . rawurlencode($slug) . '/apply', jobId: $jobId, meta: ['job_slug' => $slug]);
    }
}
