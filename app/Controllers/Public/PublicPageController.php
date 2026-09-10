<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Response;

final class PublicPageController extends Controller
{
    public function home(): Response
    {
        return view_response('public.home')->withHeader('Cache-Control', 'public, max-age=300');
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
