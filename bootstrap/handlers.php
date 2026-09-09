<?php

declare(strict_types=1);

/**
 * Global error/exception/shutdown handlers — the last line of defence for
 * failures outside the request lifecycle (bootstrap errors, fatals). The
 * front controller catches Throwables per-request and renders them via
 * App\Exceptions\Handler; these globals cover everything else.
 */

use App\Exceptions\Handler;
use App\Support\Application;
use App\Support\Logger;

return static function (Application $app): void {

    $emit = static function (Application $app, Throwable $e): void {
        try {
            /** @var Handler $handler */
            $handler = $app->get(Handler::class);
            $handler->report($e);

            if ($app->runningInConsole()) {
                fwrite(STDERR, sprintf("%s: %s\n  at %s:%d\n", $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
                exit(1);
            }

            $handler->render(null, $e)->send();
        } catch (Throwable $fatal) {
            try {
                $app->get(Logger::class)->critical('Handler failed', ['exception' => $fatal]);
            } catch (Throwable) {
            }
            if (!$app->runningInConsole() && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo $app->runningInConsole() ? "Fatal error.\n" : 'Something went wrong.';
            exit(1);
        }
    };

    $app->singleton(Handler::class, static fn (Application $app) => new Handler($app, $app->get(Logger::class)));

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $e) use ($app, $emit): void {
        $emit($app, $e);
    });

    register_shutdown_function(static function () use ($app, $emit): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $emit($app, new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        }
    });
};
