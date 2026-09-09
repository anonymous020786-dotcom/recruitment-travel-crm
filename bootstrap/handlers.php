<?php

declare(strict_types=1);

/**
 * Registers error, exception, and shutdown handlers.
 *
 * Step 1.1 renders a minimal safe response. Step 1.9 replaces the render path
 * with the full Response + error-page views once the HTTP layer exists.
 * Production never leaks stack traces, SQL, paths, or environment values.
 */

use App\Exceptions\HttpException;
use App\Support\Application;
use App\Support\Logger;

return static function (Application $app): void {

    $render = static function (Application $app, Throwable $e): void {
        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

        try {
            $app->get(Logger::class)->log(
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
                ]
            );
        } catch (Throwable) {
            // Logging must never mask the original error.
        }

        if ($app->runningInConsole()) {
            fwrite(STDERR, sprintf(
                "%s: %s\n  at %s:%d\n",
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            if ($e instanceof HttpException) {
                foreach ($e->getHeaders() as $name => $value) {
                    header($name . ': ' . $value);
                }
            }
        }

        if ($app->isDebug()) {
            echo '<!doctype html><meta charset="utf-8"><title>Error</title>';
            echo '<div style="font:14px/1.5 ui-monospace,Menlo,Consolas,monospace;padding:24px;color:#111">';
            echo '<h1 style="font-size:18px;margin:0 0 8px">' . htmlspecialchars($e::class, ENT_QUOTES) . '</h1>';
            echo '<p style="margin:0 0 4px"><strong>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</strong></p>';
            echo '<p style="color:#666;margin:0 0 16px">'
                . htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES) . '</p>';
            echo '<pre style="white-space:pre-wrap;background:#f6f6f6;padding:12px;border-radius:6px">'
                . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES) . '</pre></div>';
            return;
        }

        $messages = [
            403 => ['Access denied', 'You do not have permission to view this page.'],
            404 => ['Page not found', 'The page you are looking for could not be found.'],
            405 => ['Method not allowed', 'That action is not available here.'],
            409 => ['Conflict', 'This record changed since you opened it. Reload and try again.'],
            419 => ['Session expired', 'Your session expired. Please refresh the page and sign in again.'],
            422 => ['Could not save', 'Please review the highlighted fields and try again.'],
            429 => ['Too many requests', 'Please wait a moment and try again.'],
            503 => ['Under maintenance', 'The system is briefly unavailable. Please try again shortly.'],
        ];
        [$title, $body] = $messages[$status] ?? ['Something went wrong', 'An unexpected error occurred. If it continues, contact an administrator.'];

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>';
        echo '<style>body{font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
            . 'display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center;background:#f7f7f8;color:#1f2937}'
            . '.box{max-width:32rem;padding:2rem;text-align:center}h1{font-size:1.25rem;margin:0 0 .5rem}'
            . 'p{margin:0 0 1rem;color:#4b5563}a{color:#2563eb;text-decoration:none}code{color:#9ca3af;font-size:.8rem}</style>';
        echo '</head><body><div class="box"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>';
        echo '<p>' . htmlspecialchars($body, ENT_QUOTES) . '</p>';
        echo '<p><a href="/">Return home</a></p>';
        echo '<code>Reference: ' . htmlspecialchars(substr(md5((string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))), 0, 12), ENT_QUOTES) . '</code>';
        echo '</div></body></html>';
    };

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $e) use ($app, $render): void {
        $render($app, $e);
    });

    register_shutdown_function(static function () use ($app, $render): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $render($app, new ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            ));
        }
    });
};
