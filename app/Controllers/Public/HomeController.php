<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Http\Response;

/**
 * Public marketing site. Full pages + SEO land in a later phase; for now this
 * proves the public route group (indexable, cacheable) is wired.
 */
final class HomeController extends Controller
{
    public function index(): Response
    {
        $name = e((string) config('app.name'));

        $html = <<<HTML
        <!doctype html><html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>{$name}</title>
        <meta name="description" content="Overseas employment, recruitment and travel services.">
        </head><body style="font:16px/1.6 system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">
        <h1>{$name}</h1>
        <p>Public website coming soon. The application is under active development.</p>
        <p><a href="/login">Staff sign in</a></p>
        </body></html>
        HTML;

        return Response::html($html)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
