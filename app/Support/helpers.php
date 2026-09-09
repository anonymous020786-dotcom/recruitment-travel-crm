<?php

declare(strict_types=1);

use App\Exceptions\HttpException;
use App\Http\Response;
use App\Http\Router;
use App\Support\Application;
use App\Support\Config;
use App\Support\Logger;

if (!function_exists('app')) {
    /** @template T of object @param class-string<T>|null $abstract @return ($abstract is class-string<T> ? T : Application) */
    function app(?string $abstract = null): mixed
    {
        $app = Application::getInstance();

        return $abstract === null ? $app : $app->get($abstract);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = app(Config::class);

        return $key === null ? $config : $config->get($key, $default);
    }
}

if (!function_exists('logger')) {
    function logger(): Logger
    {
        return app(Logger::class);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return app()->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return app()->storagePath($path);
    }
}

if (!function_exists('e')) {
    /** HTML text-context escape. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('e_attr')) {
    /** HTML attribute-context escape (same rules, explicit intent). */
    function e_attr(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('e_url')) {
    /** Only allow http/https/mailto/tel; otherwise return '#'. */
    function e_url(mixed $value): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '#';
        }
        if (preg_match('#^(https?:|mailto:|tel:|/|\#|\?)#i', $url) !== 1) {
            return '#';
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('response')) {
    function response(string $body = '', int $status = 200, array $headers = []): Response
    {
        return Response::make($body, $status, $headers);
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}

if (!function_exists('route')) {
    /** @param array<string,string|int> $params */
    function route(string $name, array $params = []): string
    {
        return app(Router::class)->route($name, $params);
    }
}

if (!function_exists('redirect_route')) {
    /** @param array<string,string|int> $params */
    function redirect_route(string $name, array $params = [], int $status = 302): Response
    {
        return Response::redirect(app(Router::class)->route($name, $params), $status);
    }
}

if (!function_exists('url')) {
    /** Absolute URL from APP_URL — never from the request Host header. */
    function url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', ''), '/');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('abort')) {
    function abort(int $status, string $message = ''): never
    {
        throw new HttpException($status, $message);
    }
}

if (!function_exists('abort_unless')) {
    function abort_unless(mixed $condition, int $status, string $message = ''): void
    {
        if (!$condition) {
            throw new HttpException($status, $message);
        }
    }
}

if (!function_exists('abort_if')) {
    function abort_if(mixed $condition, int $status, string $message = ''): void
    {
        if ($condition) {
            throw new HttpException($status, $message);
        }
    }
}
