<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Request;
use App\Http\Response;
use App\Support\Application;
use App\Support\Logger;
use Throwable;

/**
 * Central exception handling: report() logs, render() turns any Throwable into
 * a safe Response. Used by the front controller (per-request) and by the
 * global handlers in bootstrap/handlers.php (fatals / non-request context).
 */
final class Handler
{
    /** Exceptions that should not be logged as errors (expected control flow). */
    private const DONT_REPORT = [
        ValidationException::class,
        AuthorizationException::class,
        StaleRecordException::class,
    ];

    private const TITLES = [
        400 => ['Bad request', 'The request could not be understood.'],
        401 => ['Sign in required', 'You need to sign in to continue.'],
        403 => ['Access denied', 'You do not have permission to view this page.'],
        404 => ['Page not found', 'The page you are looking for could not be found.'],
        405 => ['Method not allowed', 'That action is not available here.'],
        409 => ['Conflict', 'This record changed since you opened it. Reload and try again.'],
        419 => ['Session expired', 'Your session expired. Please refresh and sign in again.'],
        422 => ['Could not save', 'Please review the highlighted fields and try again.'],
        429 => ['Too many requests', 'Please wait a moment and try again.'],
        500 => ['Something went wrong', 'An unexpected error occurred. If it continues, contact an administrator.'],
        503 => ['Under maintenance', 'The system is briefly unavailable. Please try again shortly.'],
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Logger $logger,
    ) {
    }

    public function report(Throwable $e): void
    {
        foreach (self::DONT_REPORT as $type) {
            if ($e instanceof $type) {
                return;
            }
        }

        $status = $this->statusFor($e);

        $this->logger->log(
            $status >= 500 ? 'critical' : 'warning',
            'Unhandled {class}: {message}',
            [
                'class'   => $e::class,
                'message' => $e->getMessage(),
                'status'  => $status,
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'uri'     => $_SERVER['REQUEST_URI'] ?? null,
                'method'  => $_SERVER['REQUEST_METHOD'] ?? null,
                'exception' => $e,
            ],
        );
    }

    public function render(?Request $request, Throwable $e): Response
    {
        return $this->withBaselineHeaders($this->renderResponse($request, $e));
    }

    private function renderResponse(?Request $request, Throwable $e): Response
    {
        $status = $this->statusFor($e);
        $headers = $e instanceof HttpException ? $e->getHeaders() : [];

        // Errors thrown during routing (404/405) short-circuit before group
        // middleware runs, so fall back to a path check for API content negotiation.
        $wantsJson = ($request?->wantsJson() ?? false)
            || ($request !== null && str_starts_with($request->path(), '/api/'));

        if ($e instanceof ValidationException) {
            return $wantsJson
                ? Response::json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422, $headers)
                : $this->htmlError(422, $e->getMessage(), $headers);
        }

        if ($e instanceof DomainRuleException) {
            $status = $e->httpStatus();
            return $wantsJson
                ? Response::json(['message' => $e->getMessage(), 'code' => $e->ruleCode(), 'context' => $e->context()], $status, $headers)
                : $this->htmlError($status, $e->getMessage(), $headers);
        }

        if ($wantsJson) {
            $payload = ['message' => $this->publicMessage($status, $e)];
            if ($this->app->isDebug() && $status >= 500) {
                $payload['exception'] = $e::class;
                $payload['file'] = $e->getFile() . ':' . $e->getLine();
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }

            return Response::json($payload, $status, $headers);
        }

        if ($this->app->isDebug() && $status >= 500) {
            return $this->debugPage($e, $status, $headers);
        }

        return $this->htmlError($status, null, $headers);
    }

    /** Error responses always carry safe baseline headers, even if middleware never ran. */
    private function withBaselineHeaders(Response $response): Response
    {
        $baseline = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-Frame-Options'        => 'DENY',
            'X-Robots-Tag'           => 'noindex, nofollow',
            'Cache-Control'          => 'no-store, private',
        ];

        foreach ($baseline as $name => $value) {
            if ($response->getHeader($name) === null) {
                $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    private function statusFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof HttpException       => $e->getStatusCode(),
            $e instanceof ValidationException => 422,
            $e instanceof AuthorizationException => 403,
            $e instanceof StaleRecordException => 409,
            $e instanceof DomainRuleException => $e->httpStatus(),
            default => 500,
        };
    }

    /** Statuses whose HttpException message is written for end users, not just logs. */
    private const MESSAGE_SAFE_STATUSES = [419, 422, 429];

    private function publicMessage(int $status, Throwable $e): string
    {
        if (
            $e instanceof HttpException
            && $e->getMessage() !== ''
            && in_array($status, self::MESSAGE_SAFE_STATUSES, true)
        ) {
            return $e->getMessage();
        }

        return self::TITLES[$status][1] ?? self::TITLES[500][1];
    }

    private function htmlError(int $status, ?string $message, array $headers): Response
    {
        [$title, $body] = self::TITLES[$status] ?? self::TITLES[500];
        $body = $message ?? $body;
        $ref = substr(bin2hex(random_bytes(6)), 0, 12);

        $viewFile = $this->app->basePath("resources/views/errors/{$status}.php");
        if (is_file($viewFile)) {
            $html = (static function () use ($viewFile, $title, $body, $status, $ref): string {
                ob_start();
                require $viewFile;
                return (string) ob_get_clean();
            })();

            return Response::html($html, $status)->withHeaders($headers);
        }

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . '</title>'
            . '<style>body{font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
            . 'display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center;background:#f7f7f8;color:#1f2937}'
            . '.box{max-width:32rem;padding:2rem;text-align:center}h1{font-size:1.25rem;margin:0 0 .5rem}'
            . 'p{margin:0 0 1rem;color:#4b5563}a{color:#2563eb;text-decoration:none}code{color:#9ca3af;font-size:.8rem}</style>'
            . '</head><body><div class="box"><h1>' . e($title) . '</h1><p>' . e($body) . '</p>'
            . '<p><a href="/">Return home</a></p><code>Reference: ' . e($ref) . '</code></div></body></html>';

        return Response::html($html, $status)->withHeaders($headers);
    }

    private function debugPage(Throwable $e, int $status, array $headers): Response
    {
        $html = '<!doctype html><meta charset="utf-8"><title>Error</title>'
            . '<div style="font:13px/1.5 ui-monospace,Menlo,Consolas,monospace;padding:24px;color:#111">'
            . '<h1 style="font-size:18px;margin:0 0 8px">' . e($e::class) . '</h1>'
            . '<p style="margin:0 0 4px"><strong>' . e($e->getMessage()) . '</strong></p>'
            . '<p style="color:#666;margin:0 0 16px">' . e($e->getFile() . ':' . $e->getLine()) . '</p>'
            . '<pre style="white-space:pre-wrap;background:#f6f6f6;padding:12px;border-radius:6px">'
            . e($e->getTraceAsString()) . '</pre></div>';

        return Response::html($html, $status)->withHeaders($headers);
    }
}
