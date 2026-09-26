<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\HttpException;
use App\Http\Kernel;
use App\Support\Container;
use Closure;

/**
 * Method+path router with route groups (prefix / middleware / name), named
 * routes for URL generation, 404 / 405 handling, and dispatch through the
 * middleware Pipeline into a container-resolved controller.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string,Route> */
    private array $named = [];

    /** @var array{handler:mixed,middleware:list<string>}|null runs for a GET/HEAD that no route matched (CMS pages, redirects) */
    private ?array $fallback = null;

    /** @var array{prefix:string,middleware:list<string>,name:string} */
    private array $group = ['prefix' => '', 'middleware' => [], 'name' => ''];

    public function __construct(private readonly Container $container)
    {
    }

    // ---- Registration -------------------------------------------------

    public function get(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $handler);
    }

    public function post(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['POST'], $uri, $handler);
    }

    public function put(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['PUT'], $uri, $handler);
    }

    public function patch(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['PATCH'], $uri, $handler);
    }

    public function delete(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['DELETE'], $uri, $handler);
    }

    /**
     * What to do with a GET/HEAD request that matches no route at all (a request that matches a path with the wrong method is
     * still a 405). The handler may throw HttpException::notFound itself.
     *
     * @param list<string> $middleware
     */
    public function fallback(mixed $handler, array $middleware = []): void
    {
        $this->fallback = ['handler' => $handler, 'middleware' => $middleware];
    }

    public function any(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $uri, $handler);
    }

    /**
     * @param array{prefix?:string,middleware?:string|list<string>,name?:string} $attributes
     */
    public function group(array $attributes, Closure $routes): void
    {
        $previous = $this->group;

        $this->group = [
            'prefix'     => $previous['prefix'] . '/' . trim($attributes['prefix'] ?? '', '/'),
            'middleware' => array_merge($previous['middleware'], (array) ($attributes['middleware'] ?? [])),
            'name'       => $previous['name'] . ($attributes['name'] ?? ''),
        ];

        $routes($this);

        $this->group = $previous;
    }

    private function addRoute(array $methods, string $uri, mixed $handler): Route
    {
        $fullUri = rtrim($this->group['prefix'], '/') . '/' . trim($uri, '/');
        $fullUri = '/' . trim($fullUri, '/');

        $route = new Route($methods, $fullUri, $handler);
        $route->middleware($this->group['middleware']);
        if ($this->group['name'] !== '') {
            $route->name($this->group['name']);
        }

        $this->routes[] = $route;

        return $route;
    }

    /** @return list<Route> every registered route, in registration order (used by the route audit) */
    public function routes(): array
    {
        return $this->routes;
    }

    public function finalizeNames(): void
    {
        foreach ($this->routes as $route) {
            if ($route->name !== null) {
                $this->named[$route->name] = $route;
            }
        }
    }

    // ---- URL generation --------------------------------------------

    /** @param array<string,string|int> $params */
    public function route(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            $this->finalizeNames();
        }
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException("Route [{$name}] is not defined.");
        }

        return $this->named[$name]->url($params);
    }

    public function hasRoute(string $name): bool
    {
        if ($this->named === []) {
            $this->finalizeNames();
        }

        return isset($this->named[$name]);
    }

    // ---- Dispatch --------------------------------------------------

    public function dispatch(Request $request): Response
    {
        $this->finalizeNames();

        $method = $request->method();
        $path = $request->path();

        $matchedOtherMethod = [];

        $kernel = $this->container->get(Kernel::class);

        foreach ($this->routes as $route) {
            $params = $route->match($method, $path);
            if ($params !== null) {
                $request->setRouteParams($params);

                return (new Pipeline($this->container))
                    ->send($request)
                    ->through($kernel->expand($route->middleware))
                    ->then(fn (Request $req) => $this->runHandler($route, $req));
            }

            if ($route->matchesPathOnly($path)) {
                $matchedOtherMethod = array_merge($matchedOtherMethod, $route->methods);
            }
        }

        if ($matchedOtherMethod !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($matchedOtherMethod)));
        }

        if ($this->fallback !== null && in_array($method, ['GET', 'HEAD'], true)) {
            $fallback = $this->fallback;

            return (new Pipeline($this->container))
                ->send($request)
                ->through($kernel->expand($fallback['middleware']))
                ->then(fn (Request $req) => $this->toResponse($this->container->call($fallback['handler'], $this->handlerArgs($req))));
        }

        throw HttpException::notFound("No route for {$method} {$path}");
    }

    private function runHandler(Route $route, Request $request): Response
    {
        $handler = $route->handler;

        $result = match (true) {
            $handler instanceof Closure => $this->container->call($handler, $this->handlerArgs($request)),
            is_array($handler) => $this->container->call($handler, $this->handlerArgs($request)),
            is_string($handler) && str_contains($handler, '@') => (function () use ($handler, $request) {
                [$class, $methodName] = explode('@', $handler, 2);

                return $this->container->call([$class, $methodName], $this->handlerArgs($request));
            })(),
            default => throw new \RuntimeException('Unsupported route handler.'),
        };

        return $this->toResponse($result);
    }

    /** @return array<string,mixed> */
    private function handlerArgs(Request $request): array
    {
        return array_merge(
            $request->routeParams(),
            ['request' => $request, Request::class => $request],
        );
    }

    private function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_array($result), is_object($result) && !($result instanceof \Stringable) => Response::json($result),
            is_string($result), $result instanceof \Stringable => Response::html((string) $result),
            $result === null => Response::noContent(),
            default => Response::text((string) $result),
        };
    }
}
