<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Lead read model. Hydrated from a `leads` row joined to its status / source /
 * assignee for display. Immutable.
 */
final class Lead
{
    /** @param array<string,mixed> $raw the original row, for edit forms / audit diffs */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $leadNumber,
        public readonly int $branchId,
        public readonly ?int $personId,
        public readonly string $name,
        public readonly string $phone,
        public readonly ?string $alternatePhone,
        public readonly ?string $email,
        public readonly ?string $gender,
        public readonly ?string $dateOfBirth,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?int $sourceId,
        public readonly ?string $sourceName,
        public readonly ?string $campaign,
        public readonly ?string $interestedCountry,
        public readonly ?string $interestedJob,
        public readonly ?float $experienceYears,
        public readonly ?string $qualification,
        public readonly ?string $salaryExpectation,
        public readonly ?string $salaryCurrency,
        public readonly string $priority,
        public readonly int $statusId,
        public readonly string $statusKey,
        public readonly string $statusLabel,
        public readonly bool $statusIsTerminal,
        public readonly bool $statusIsWon,
        public readonly ?int $assignedTo,
        public readonly ?string $assignedToName,
        public readonly ?string $convertedAt,
        public readonly ?int $convertedCandidateId,
        public readonly ?string $lostReason,
        public readonly ?string $notes,
        public readonly int $recordVersion,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            leadNumber: (string) $r['lead_number'],
            branchId: (int) $r['branch_id'],
            personId: isset($r['person_id']) ? (int) $r['person_id'] : null,
            name: (string) $r['name'],
            phone: (string) $r['phone'],
            alternatePhone: $r['alternate_phone'] ?? null,
            email: $r['email'] ?? null,
            gender: $r['gender'] ?? null,
            dateOfBirth: $r['date_of_birth'] ?? null,
            city: $r['city'] ?? null,
            state: $r['state'] ?? null,
            sourceId: isset($r['source_id']) ? (int) $r['source_id'] : null,
            sourceName: $r['source_name'] ?? null,
            campaign: $r['campaign'] ?? null,
            interestedCountry: $r['interested_country'] ?? null,
            interestedJob: $r['interested_job'] ?? null,
            experienceYears: isset($r['experience_years']) ? (float) $r['experience_years'] : null,
            qualification: $r['qualification'] ?? null,
            salaryExpectation: isset($r['salary_expectation']) ? (string) $r['salary_expectation'] : null,
            salaryCurrency: $r['salary_currency'] ?? null,
            priority: (string) ($r['priority'] ?? 'medium'),
            statusId: (int) $r['status_id'],
            statusKey: (string) ($r['status_key'] ?? ''),
            statusLabel: (string) ($r['status_label'] ?? ''),
            statusIsTerminal: (bool) ($r['status_is_terminal'] ?? false),
            statusIsWon: (bool) ($r['status_is_won'] ?? false),
            assignedTo: isset($r['assigned_to']) ? (int) $r['assigned_to'] : null,
            assignedToName: $r['assigned_to_name'] ?? null,
            convertedAt: $r['converted_at'] ?? null,
            convertedCandidateId: isset($r['converted_candidate_id']) ? (int) $r['converted_candidate_id'] : null,
            lostReason: $r['lost_reason'] ?? null,
            notes: $r['notes'] ?? null,
            recordVersion: (int) ($r['record_version'] ?? 1),
            createdBy: isset($r['created_by']) ? (int) $r['created_by'] : null,
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
            raw: $r,
        );
    }

    public function isConverted(): bool
    {
        return $this->convertedCandidateId !== null;
    }

    public function isEditable(): bool
    {
        return $this->convertedCandidateId === null;
    }

    /** Colour token for the status pill component. */
    public function statusColor(): string
    {
        return match ($this->statusKey) {
            'new'            => 'blue',
            'contacted'      => 'indigo',
            'follow_up'      => 'amber',
            'interested'     => 'violet',
            'counselling'    => 'brand',
            'converted'      => 'emerald',
            'not_interested' => 'slate',
            'lost'           => 'rose',
            default          => 'slate',
        };
    }

    public function priorityColor(): string
    {
        return match ($this->priority) {
            'urgent' => 'red',
            'high'   => 'amber',
            'medium' => 'slate',
            'low'    => 'gray',
            default  => 'slate',
        };
    }
}
