<?php

declare(strict_types=1);

namespace App\Domain;

use App\Exceptions\DomainRuleException;

/**
 * Pure, I/O-free status transition engine. Loaded with a rules array
 * (config('statuses')). Services call assert()/canTransition() before writing a
 * status change + a *_status_history row.
 *
 * Overrides: an actor holding `<module>.override_status` may perform a
 * transition not in the allowlist; the service passes `allowOverride: true` and
 * records is_override = 1 with a reason.
 */
final class StatusMachine
{
    /** @param array<string,array<string,list<string>>> $rules entity => from => [to,...] */
    public function __construct(private readonly array $rules)
    {
    }

    /** @return list<string> statuses reachable from $from for $entity */
    public function transitionsFrom(string $entity, string $from): array
    {
        return $this->rules[$entity][$from] ?? [];
    }

    public function isKnown(string $entity, string $status): bool
    {
        $map = $this->rules[$entity] ?? [];
        if (isset($map[$status])) {
            return true;
        }
        foreach ($map as $targets) {
            if (in_array($status, $targets, true)) {
                return true;
            }
        }

        return false;
    }

    public function isTerminal(string $entity, string $status): bool
    {
        return ($this->rules[$entity][$status] ?? null) === [];
    }

    public function canTransition(string $entity, string $from, string $to): bool
    {
        return in_array($to, $this->transitionsFrom($entity, $from), true);
    }

    /**
     * @throws DomainRuleException when the transition is not allowed and no override
     * @return bool true if this was an override (out-of-allowlist but permitted)
     */
    public function assert(string $entity, string $from, string $to, bool $allowOverride = false): bool
    {
        if ($from === $to) {
            throw new DomainRuleException(
                DomainRuleException::INVALID_TRANSITION,
                "The status is already \"{$to}\".",
                ['entity' => $entity, 'from' => $from, 'to' => $to],
            );
        }

        if (!isset($this->rules[$entity])) {
            throw new DomainRuleException(
                DomainRuleException::RULE_VIOLATION,
                "No status rules are defined for {$entity}.",
                ['entity' => $entity],
            );
        }

        if ($this->canTransition($entity, $from, $to)) {
            return false;
        }

        if (!$this->isKnown($entity, $to)) {
            throw new DomainRuleException(
                DomainRuleException::INVALID_TRANSITION,
                "\"{$to}\" is not a valid {$entity} status.",
                ['entity' => $entity, 'from' => $from, 'to' => $to, 'allowed' => $this->transitionsFrom($entity, $from)],
            );
        }

        if ($allowOverride) {
            return true;
        }

        throw new DomainRuleException(
            DomainRuleException::INVALID_TRANSITION,
            "Cannot move a {$entity} from \"{$from}\" to \"{$to}\".",
            ['entity' => $entity, 'from' => $from, 'to' => $to, 'allowed' => $this->transitionsFrom($entity, $from)],
        );
    }
}
