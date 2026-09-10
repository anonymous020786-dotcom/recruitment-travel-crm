<?php

declare(strict_types=1);

use App\Exceptions\HttpException;
use App\Http\Response;
use App\Http\Router;
use App\Session\Session;
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

if (!function_exists('session')) {
    function session(): ?Session
    {
        return app()->bound(Session::class) ? app(Session::class) : null;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        $session = session();
        if ($session !== null) {
            return $session->token();
        }

        // Public (session-free) pages: a short-lived signed token, verified
        // statelessly by VerifyCsrf.
        try {
            return 's:' . app(\App\Support\Signer::class)->timedToken(3600);
        } catch (\Throwable) {
            return '';
        }
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = null): mixed
    {
        $bag = session()?->get('_old_input');

        return is_array($bag) ? ($bag[$key] ?? $default) : $default;
    }
}

if (!function_exists('errors')) {
    /** @return array<string,list<string>> */
    function errors(): array
    {
        $bag = session()?->get('_errors');

        return is_array($bag) ? $bag : [];
    }
}

if (!function_exists('error')) {
    function error(string $field): ?string
    {
        return errors()[$field][0] ?? null;
    }
}

if (!function_exists('flash')) {
    function flash(string $key, mixed $value): void
    {
        session()?->flash($key, $value);
    }
}

if (!function_exists('view')) {
    /** @param array<string,mixed> $data */
    function view(string $name, array $data = []): string
    {
        return app(\App\View\View::class)->render($name, $data);
    }
}

if (!function_exists('asset')) {
    function asset(string $key): string
    {
        return app(\App\View\Assets::class)->url($key);
    }
}

if (!function_exists('component')) {
    /** Render a component partial: component('button', ['label' => 'Save']) */
    function component(string $name, array $props = []): string
    {
        return app(\App\View\View::class)->partial('components.' . $name, $props);
    }
}

if (!function_exists('nonce')) {
    function nonce(): string
    {
        $r = app()->bound(\App\Http\Request::class) ? app(\App\Http\Request::class) : null;

        return (string) ($r?->attribute('csp_nonce') ?? '');
    }
}

if (!function_exists('integrations')) {
    function integrations(): \App\Integrations\IntegrationsService
    {
        return app(\App\Integrations\IntegrationsService::class);
    }
}

if (!function_exists('view_response')) {
    /** @param array<string,mixed> $data */
    function view_response(string $name, array $data = [], int $status = 200): \App\Http\Response
    {
        return \App\Http\Response::html(view($name, $data), $status);
    }
}

if (!function_exists('back')) {
    function back(int $status = 302): \App\Http\Response
    {
        return \App\Http\Response::redirect(session()?->previousUrl() ?? '/', $status);
    }
}

if (!function_exists('redirect_with_errors')) {
    /**
     * Flash validation errors + old input, then redirect back (or to $to).
     *
     * @param array<string,list<string>> $errorBag
     * @param array<string,mixed> $oldInput
     */
    function redirect_with_errors(array $errorBag, array $oldInput = [], ?string $to = null): \App\Http\Response
    {
        $session = session();
        if ($session !== null) {
            $session->flash('_errors', $errorBag);
            $redacted = array_diff_key($oldInput, array_flip(['password', 'password_confirmation', 'current_password', '_token']));
            $session->flash('_old_input', $redacted);
        }

        return \App\Http\Response::redirect($to ?? ($session?->previousUrl() ?? '/'));
    }
}

if (!function_exists('auth')) {
    function auth(): \App\Auth\Auth
    {
        return app(\App\Auth\Auth::class);
    }
}

if (!function_exists('user')) {
    function user(): ?\App\Models\User
    {
        return auth()->user();
    }
}

if (!function_exists('gate')) {
    function gate(): \App\Auth\Gate
    {
        return app(\App\Auth\Gate::class);
    }
}

if (!function_exists('can')) {
    function can(string $ability, mixed ...$arguments): bool
    {
        return gate()->allows($ability, ...$arguments);
    }
}

if (!function_exists('cannot')) {
    function cannot(string $ability, mixed ...$arguments): bool
    {
        return gate()->denies($ability, ...$arguments);
    }
}

if (!function_exists('authorize')) {
    function authorize(string $ability, mixed ...$arguments): void
    {
        gate()->authorize($ability, ...$arguments);
    }
}

if (!function_exists('branch_scope')) {
    function branch_scope(): ?\App\Auth\BranchScope
    {
        return app()->bound(\App\Auth\BranchScope::class) ? app(\App\Auth\BranchScope::class) : null;
    }
}

if (!function_exists('audit')) {
    function audit(): \App\Audit\AuditService
    {
        return app(\App\Audit\AuditService::class);
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
