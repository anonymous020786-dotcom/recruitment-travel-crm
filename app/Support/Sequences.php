<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Gap-free per-scope counters backed by `number_sequences`. Used for document
 * numbers that must not have holes (lead_number, candidate_number, invoice_number,
 * receipt_number, ...).
 *
 * `next()` MUST be called inside a transaction opened by the caller — it takes a
 * row lock (`SELECT ... FOR UPDATE`) that is released on COMMIT/ROLLBACK, so the
 * allocated number is only "spent" if the surrounding business write commits.
 */
final class Sequences
{
    public function __construct(
        private readonly Db $db,
        private readonly string $table = 'number_sequences',
    ) {
    }

    /**
     * Reserve and return the next integer for a scope (e.g. "lead:2026").
     * The caller must be inside a transaction.
     */
    public function nextValue(string $scope): int
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Sequences::nextValue() must be called inside a transaction.');
        }

        $current = $this->db->selectValue(
            "SELECT next_value FROM `{$this->table}` WHERE scope = :s FOR UPDATE",
            ['s' => $scope],
        );

        if ($current === null) {
            // INSERT IGNORE handles the race where two transactions seed the same
            // scope; we then re-read under lock.
            $this->db->affectingStatement(
                "INSERT IGNORE INTO `{$this->table}` (scope, next_value) VALUES (:s, 1)",
                ['s' => $scope],
            );
            $current = (int) $this->db->selectValue(
                "SELECT next_value FROM `{$this->table}` WHERE scope = :s FOR UPDATE",
                ['s' => $scope],
                1,
            );
        }

        $value = (int) $current;

        $this->db->affectingStatement(
            "UPDATE `{$this->table}` SET next_value = next_value + 1 WHERE scope = :s",
            ['s' => $scope],
        );

        return $value;
    }

    /**
     * Formatted document number, e.g. next('lead', 'LEAD', 6) -> "LEAD-2026-000123"
     * (scope is suffixed with the current year).
     */
    public function next(string $scope, string $prefix, int $pad = 6): string
    {
        $year = date('Y');
        $value = $this->nextValue("{$scope}:{$year}");

        return sprintf('%s-%s-%s', $prefix, $year, str_pad((string) $value, $pad, '0', STR_PAD_LEFT));
    }
}
