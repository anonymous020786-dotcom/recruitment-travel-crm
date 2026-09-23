<?php

declare(strict_types=1);

namespace App\Models;

/** A job posting (`jobs` joined to its employer). Immutable read model. */
final class Job
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $jobNumber,
        public readonly string $slug,
        public readonly string $title,
        public readonly int $employerId,
        public readonly string $employerName,
        public readonly string $employerPublicId,
        public readonly ?int $branchId,
        public readonly string $country,
        public readonly ?string $city,
        public readonly int $vacancies,
        public readonly ?float $salaryMin,
        public readonly ?float $salaryMax,
        public readonly ?string $currency,
        public readonly ?string $experienceRequired,
        public readonly ?string $qualification,
        public readonly ?int $ageMin,
        public readonly ?int $ageMax,
        public readonly string $genderRequirement,
        public readonly string $accommodation,
        public readonly string $food,
        public readonly string $transport,
        public readonly ?string $workingHours,
        public readonly ?string $overtime,
        public readonly ?int $contractDurationMonths,
        public readonly ?string $interviewType,
        public readonly ?string $deadline,
        public readonly string $status,
        public readonly bool $isPublic,
        public readonly ?string $descriptionHtml,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $r */
    public static function fromRow(array $r): self
    {
        $f = static fn (string $k): ?float => isset($r[$k]) ? (float) $r[$k] : null;
        $i = static fn (string $k): ?int => isset($r[$k]) ? (int) $r[$k] : null;

        return new self(
            id: (int) $r['id'],
            publicId: (string) $r['public_id'],
            jobNumber: (string) $r['job_number'],
            slug: (string) $r['slug'],
            title: (string) $r['title'],
            employerId: (int) $r['employer_id'],
            employerName: (string) ($r['employer_name'] ?? ''),
            employerPublicId: (string) ($r['employer_public_id'] ?? ''),
            branchId: $i('branch_id'),
            country: (string) $r['country'],
            city: $r['city'] ?? null,
            vacancies: (int) $r['vacancies'],
            salaryMin: $f('salary_min'),
            salaryMax: $f('salary_max'),
            currency: $r['currency'] ?? null,
            experienceRequired: $r['experience_required'] ?? null,
            qualification: $r['qualification'] ?? null,
            ageMin: $i('age_min'),
            ageMax: $i('age_max'),
            genderRequirement: (string) $r['gender_requirement'],
            accommodation: (string) $r['accommodation'],
            food: (string) $r['food'],
            transport: (string) $r['transport'],
            workingHours: $r['working_hours'] ?? null,
            overtime: $r['overtime'] ?? null,
            contractDurationMonths: $i('contract_duration_months'),
            interviewType: $r['interview_type'] ?? null,
            deadline: $r['deadline'] ?? null,
            status: (string) $r['status'],
            isPublic: (bool) $r['is_public'],
            descriptionHtml: $r['description_html'] ?? null,
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at'],
        );
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }

    public function salaryLabel(): string
    {
        if ($this->salaryMin === null && $this->salaryMax === null) {
            return '—';
        }
        $fmt = static fn (?float $v): string => $v === null ? '' : rtrim(rtrim(number_format($v, 2), '0'), '.');
        $range = $this->salaryMin !== null && $this->salaryMax !== null && $this->salaryMin !== $this->salaryMax
            ? $fmt($this->salaryMin) . '–' . $fmt($this->salaryMax)
            : $fmt($this->salaryMin ?? $this->salaryMax);

        return trim($range . ' ' . ($this->currency ?? ''));
    }

    public function isDeadlinePassed(?string $today = null): bool
    {
        return $this->deadline !== null && $this->deadline < ($today ?? gmdate('Y-m-d'));
    }
}
