<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A single registered route. Mutable only through the fluent helpers used at
 * registration time (name/middleware).
 */
final class Route
{
    public ?string $name = null;

    /** @var list<string> */
    public array $middleware = [];

    private string $regex;

    /** @var list<string> */
    private array $paramNames = [];

    /**
     * @param list<string> $methods
     * @param array{0:class-string,1:string}|string|\Closure $handler
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $uri,
        public readonly mixed $handler,
    ) {
        $this->compile();
    }

    public function name(string $name): self
    {
        $this->name = ($this->name ?? '') . $name;

        return $this;
    }

    /** @param string|list<string> $middleware */
    public function middleware(string|array $middleware): self
    {
        $this->middleware = array_merge($this->middleware, (array) $middleware);

        return $this;
    }

    /** @return array<string,string>|null param map if the path matches */
    public function match(string $method, string $path): ?array
    {
        if (!in_array($method, $this->methods, true)) {
            return null;
        }

        if (!preg_match($this->regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            if (isset($matches[$name]) && $matches[$name] !== '') {
                $params[$name] = rawurldecode($matches[$name]);
            }
        }

        return $params;
    }

    public function matchesPathOnly(string $path): bool
    {
        return (bool) preg_match($this->regex, $path);
    }

    /** @param array<string,string|int> $params */
    public function url(array $params = []): string
    {
        $uri = $this->uri;

        foreach ($params as $key => $value) {
            $uri = preg_replace('/\{' . preg_quote((string) $key, '/') . '\??\}/', rawurlencode((string) $value), $uri);
        }

        // Drop any leftover optional params; fail loudly on missing required ones.
        $uri = preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\?\}/', '', $uri);
        if (preg_match('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $uri, $m)) {
            throw new \InvalidArgumentException("Missing route parameter [{$m[1]}] for " . ($this->name ?? $this->uri));
        }

        return '/' . trim($uri, '/');
    }

    private function compile(): void
    {
        $uri = '/' . trim($this->uri, '/');

        if ($uri === '/') {
            $this->regex = '#^/?$#';

            return;
        }

        $regex = '';
        foreach (explode('/', ltrim($uri, '/')) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}$/', $segment, $m) === 1) {
                $this->paramNames[] = $m[1];
                // One path segment: ULIDs, numeric ids, slugs, country codes.
                $capture = '(?<' . $m[1] . '>[A-Za-z0-9._~-]+)';
                $regex .= ($m[2] ?? '') === '?' ? '(?:/' . $capture . ')?' : '/' . $capture;
            } else {
                $regex .= '/' . preg_quote($segment, '#');
            }
        }

        $this->regex = '#^' . $regex . '/?$#';
    }
}
