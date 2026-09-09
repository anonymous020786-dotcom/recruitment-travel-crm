<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\Middleware;
use App\Support\Container;
use Closure;

/**
 * Runs an ordered list of middleware around a final handler ("onion" model).
 * Middleware may be a class name (resolved from the container), an instance,
 * or a `Class:arg1,arg2` string whose args are passed to make().
 */
final class Pipeline
{
    /** @var list<string|Middleware> */
    private array $middleware = [];
    private Closure $destination;

    public function __construct(private readonly Container $container)
    {
    }

    public function send(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    private Request $request;

    /** @param list<string|Middleware> $middleware */
    public function through(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    public function then(Closure $destination): Response
    {
        $this->destination = $destination;

        $pipeline = array_reduce(
            array_reverse($this->middleware),
            $this->carry(),
            function (Request $request): Response {
                return ($this->destination)($request);
            },
        );

        return $pipeline($this->request);
    }

    private function carry(): Closure
    {
        return function (Closure $next, string|Middleware $entry): Closure {
            return function (Request $request) use ($next, $entry): Response {
                $instance = $this->resolve($entry);

                return $instance->handle($request, $next);
            };
        };
    }

    private function resolve(string|Middleware $entry): Middleware
    {
        if ($entry instanceof Middleware) {
            return $entry;
        }

        $params = [];
        if (str_contains($entry, ':')) {
            [$entry, $argString] = explode(':', $entry, 2);
            $params = ['args' => array_map('trim', explode(',', $argString))];
        }

        $instance = $this->container->make($entry, $params);

        if (!$instance instanceof Middleware) {
            throw new \RuntimeException("[{$entry}] is not a Middleware.");
        }

        return $instance;
    }
}
