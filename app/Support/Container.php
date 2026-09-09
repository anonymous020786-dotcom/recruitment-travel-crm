<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Small dependency-injection container with constructor autowiring.
 * Intentionally minimal — no framework, shared-hosting friendly.
 */
class Container
{
    /** @var array<string,Closure> */
    private array $bindings = [];

    /** @var array<string,object> */
    private array $instances = [];

    /** @var array<string,bool> */
    private array $shared = [];

    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->bindings[$id] = $this->normalize($id, $concrete);
        $this->shared[$id] = $shared;
        unset($this->instances[$id]);
    }

    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
        $this->shared[$id] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    /** @template T of object @param class-string<T>|string $id @return ($id is class-string<T> ? T : mixed) */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    public function make(string $id, array $parameters = []): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $concrete = $this->bindings[$id] ?? null;

        $object = $concrete instanceof Closure
            ? $concrete($this, $parameters)
            : $this->build($id, $parameters);

        if (($this->shared[$id] ?? false) === true) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    public function call(callable|array $callable, array $parameters = []): mixed
    {
        if (is_array($callable)) {
            [$class, $method] = $callable;
            $instance = is_object($class) ? $class : $this->make($class);
            $ref = new \ReflectionMethod($instance, $method);
            return $ref->invokeArgs($instance, $this->resolveDependencies($ref->getParameters(), $parameters));
        }

        $ref = new \ReflectionFunction(Closure::fromCallable($callable));

        return $ref->invokeArgs($this->resolveDependencies($ref->getParameters(), $parameters));
    }

    private function normalize(string $id, Closure|string|null $concrete): Closure
    {
        if ($concrete instanceof Closure) {
            return $concrete;
        }

        $target = $concrete ?? $id;

        return fn (Container $c, array $params = []) => $c->build($target, $params);
    }

    private function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Cannot resolve [{$class}] from the container.");
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Class [{$class}] is not instantiable.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        return $reflection->newInstanceArgs(
            $this->resolveDependencies($constructor->getParameters(), $parameters)
        );
    }

    /**
     * @param array<\ReflectionParameter> $params
     * @return list<mixed>
     */
    private function resolveDependencies(array $params, array $overrides): array
    {
        $resolved = [];

        foreach ($params as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $overrides)) {
                $resolved[] = $overrides[$name];
                continue;
            }

            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $resolved[] = $this->make($type->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $resolved[] = $param->getDefaultValue();
                continue;
            }

            if ($param->allowsNull()) {
                $resolved[] = null;
                continue;
            }

            throw new RuntimeException("Unresolvable dependency \${$name} for " . ($param->getDeclaringClass()?->getName() ?? 'closure'));
        }

        return $resolved;
    }
}
