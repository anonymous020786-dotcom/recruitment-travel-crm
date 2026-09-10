<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\StatusMachine;
use App\Exceptions\DomainRuleException;
use PHPUnit\Framework\TestCase;

final class StatusMachineTest extends TestCase
{
    private function machine(): StatusMachine
    {
        return new StatusMachine([
            'lead' => [
                'new'       => ['contacted', 'lost'],
                'contacted' => ['interested', 'lost'],
                'interested' => ['converted', 'lost'],
                'converted' => [],
                'lost'      => ['contacted'],
            ],
        ]);
    }

    public function test_allowed_transition_passes_and_is_not_override(): void
    {
        self::assertFalse($this->machine()->assert('lead', 'new', 'contacted'));
        self::assertTrue($this->machine()->canTransition('lead', 'contacted', 'interested'));
    }

    public function test_disallowed_transition_throws(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->machine()->assert('lead', 'new', 'interested');
    }

    public function test_disallowed_transition_permitted_with_override_flag(): void
    {
        self::assertTrue($this->machine()->assert('lead', 'new', 'interested', allowOverride: true));
    }

    public function test_unknown_target_status_always_throws_even_with_override(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->machine()->assert('lead', 'new', 'banana', allowOverride: true);
    }

    public function test_same_status_throws(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->machine()->assert('lead', 'new', 'new');
    }

    public function test_terminal_detection(): void
    {
        self::assertTrue($this->machine()->isTerminal('lead', 'converted'));
        self::assertFalse($this->machine()->isTerminal('lead', 'new'));
        self::assertSame([], $this->machine()->transitionsFrom('lead', 'converted'));
    }

    public function test_unknown_entity_throws(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->machine()->assert('unicorn', 'a', 'b');
    }
}
