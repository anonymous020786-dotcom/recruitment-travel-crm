<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Container;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function test_binds_and_resolves_closure(): void
    {
        $c = new Container();
        $c->bind('greeting', fn () => 'hello');
        self::assertSame('hello', $c->get('greeting'));
    }

    public function test_singleton_returns_same_instance(): void
    {
        $c = new Container();
        $c->singleton(ContainerFixtureA::class);
        self::assertSame($c->get(ContainerFixtureA::class), $c->get(ContainerFixtureA::class));
    }

    public function test_non_shared_returns_fresh_instance(): void
    {
        $c = new Container();
        $c->bind(ContainerFixtureA::class);
        self::assertNotSame($c->get(ContainerFixtureA::class), $c->get(ContainerFixtureA::class));
    }

    public function test_autowires_nested_dependencies(): void
    {
        $c = new Container();
        $b = $c->get(ContainerFixtureB::class);
        self::assertInstanceOf(ContainerFixtureA::class, $b->a);
    }

    public function test_uses_default_for_unresolvable_scalar(): void
    {
        $c = new Container();
        $obj = $c->get(ContainerFixtureC::class);
        self::assertSame('default', $obj->name);
    }

    public function test_call_invokes_with_injection(): void
    {
        $c = new Container();
        $result = $c->call(fn (ContainerFixtureA $a) => $a::class);
        self::assertSame(ContainerFixtureA::class, $result);
    }
}

class ContainerFixtureA
{
}

class ContainerFixtureB
{
    public function __construct(public ContainerFixtureA $a)
    {
    }
}

class ContainerFixtureC
{
    public function __construct(public string $name = 'default')
    {
    }
}
