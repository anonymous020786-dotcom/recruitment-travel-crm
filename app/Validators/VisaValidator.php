<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/** Validates the visa create / edit form and the status-change form. */
final class VisaValidator
{
    private const DETAIL_RULES = [
        'country'          => ['required', 'regex:/^[A-Za-z]{2}$/'],
        'visa_type'        => 'nullable|string|max:80',
        'visa_number'      => 'nullable|string|max:80',
        'reference_number' => 'nullable|string|max:80',
        'sponsor'          => 'nullable|string|max:180',
        'notes'            => 'nullable|string|max:2000',
    ];

    private const STATUS_RULES = [
        'status'          => 'required|in:not_started,documents_pending,submitted,under_processing,approved,rejected,expired,cancelled',
        'reason'          => 'nullable|string|max:255',
        'visa_number'     => 'nullable|string|max:80',
        'submission_date' => 'nullable|date',
        'approval_date'   => 'nullable|date',
        'expiry_date'     => 'nullable|date',
    ];

    private const MESSAGES = ['country.regex' => 'Use a two-letter country code such as AE.'];

    /**
     * Create / edit form.
     *
     * @param array<string,mixed> $data
     * @return array{country:string,visa_type:?string,visa_number:?string,reference_number:?string,sponsor:?string,notes:?string}
     */
    public function details(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::DETAIL_RULES, self::MESSAGES)->validated();

        return [
            'country'          => strtoupper((string) $clean['country']),
            'visa_type'        => $this->blank($clean['visa_type'] ?? null),
            'visa_number'      => $this->blank($clean['visa_number'] ?? null),
            'reference_number' => $this->blank($clean['reference_number'] ?? null),
            'sponsor'          => $this->blank($clean['sponsor'] ?? null),
            'notes'            => $this->blank($clean['notes'] ?? null),
        ];
    }

    /**
     * Status-change form.
     *
     * @param array<string,mixed> $data
     * @return array{status:string,reason:?string,visa_number:?string,submission_date:?string,approval_date:?string,expiry_date:?string}
     */
    public function status(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::STATUS_RULES)->validated();

        $out = [
            'status'          => (string) $clean['status'],
            'reason'          => $this->blank($clean['reason'] ?? null),
            'visa_number'     => $this->blank($clean['visa_number'] ?? null),
            'submission_date' => $this->date($clean['submission_date'] ?? null, 'submission_date'),
            'approval_date'   => $this->date($clean['approval_date'] ?? null, 'approval_date'),
            'expiry_date'     => $this->date($clean['expiry_date'] ?? null, 'expiry_date'),
        ];

        $today = gmdate('Y-m-d');
        foreach (['submission_date', 'approval_date'] as $f) {
            if ($out[$f] !== null && $out[$f] > $today) {
                throw new ValidationException([$f => ['This date cannot be in the future.']]);
            }
        }

        return $out;
    }

    private function blank(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? (string) $v : null;
    }

    private function date(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new ValidationException([$field => ['Use a valid date.']]);
        }

        return $d->format('Y-m-d');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function trim(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }

        return $data;
    }
}
