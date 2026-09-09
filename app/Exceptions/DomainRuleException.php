<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by services when a business rule is violated (invalid status
 * transition, duplicate lead, refund exceeds paid amount, allocation exceeds
 * balance, ...). Carries a machine code plus optional structured context so the
 * controller can render an actionable message (e.g. list of possible duplicates).
 */
final class DomainRuleException extends RuntimeException
{
    public const INVALID_TRANSITION      = 'invalid_transition';
    public const DUPLICATE_LEAD          = 'duplicate_lead';
    public const REFUND_EXCEEDS_PAID     = 'refund_exceeds_paid';
    public const ALLOCATION_EXCEEDS_BAL  = 'allocation_exceeds_balance';
    public const IDEMPOTENT_REPLAY       = 'idempotent_replay';
    public const RULE_VIOLATION          = 'rule_violation';

    /** @param array<string,mixed> $context */
    public function __construct(
        private readonly string $code_,
        string $message,
        private readonly array $context = [],
        private readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public function ruleCode(): string
    {
        return $this->code_;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
