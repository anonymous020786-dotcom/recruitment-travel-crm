<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;

/**
 * Thin base controller. Controllers orchestrate: parse request → validate →
 * call ONE service method → return a Response. No SQL, no transactions, no
 * multi-step business workflow here.
 */
abstract class Controller
{
    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function noContent(): Response
    {
        return Response::noContent();
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}
