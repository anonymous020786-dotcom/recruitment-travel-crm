<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Response;
use App\Repositories\PublicCatalogRepository;

final class PublicPageController extends Controller
{
    public function __construct(private readonly PublicCatalogRepository $catalog)
    {
    }

    public function home(): Response
    {
        return view_response('public.home', [
            'latestJobs' => $this->catalog->jobs('', '', 1, 6)['rows'],
            'latestPackages' => $this->catalog->packages('', 1, 3)['rows'],
        ])->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function about(): Response
    {
        return view_response('public.about')->withHeader('Cache-Control', 'public, max-age=600');
    }

    public function contact(): Response
    {
        // Contains a signed CSRF token, so it must not be shared-cached.
        return view_response('public.contact')->withHeader('Cache-Control', 'private, max-age=0');
    }
}
