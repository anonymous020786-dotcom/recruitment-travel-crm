<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/**
 * `preferred_countries` / `preferred_job_titles` arrive as comma-separated
 * text (no multi-select widget in this framework) and are normalised into
 * plain lists here before the service JSON-encodes them for storage.
 */
final class CandidatePreferencesValidator
{
    private const RULES = [
        'min_expected_salary' => 'nullable|numeric|min:0|max:99999999',
        'salary_currency'     => 'nullable|string|size:3|alpha',
        'willing_to_relocate' => 'nullable|boolean',
        'available_from'      => 'nullable|date',
        'passport_ready'      => 'nullable|boolean',
        'notes'               => 'nullable|string|max:500',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function validate(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }

        $clean = Validator::make($data, self::RULES)->validated();

        $clean['willing_to_relocate'] = in_array($clean['willing_to_relocate'] ?? true, [true, 1, '1', 'on', 'true'], true);
        $clean['passport_ready'] = in_array($clean['passport_ready'] ?? false, [true, 1, '1', 'on', 'true'], true);
        $clean['salary_currency'] = ($clean['salary_currency'] ?? '') !== '' ? strtoupper((string) $clean['salary_currency']) : null;
        $clean['min_expected_salary'] = ($clean['min_expected_salary'] ?? '') !== '' ? (float) $clean['min_expected_salary'] : null;
        $clean['available_from'] = ($clean['available_from'] ?? '') !== '' ? $clean['available_from'] : null;
        $clean['notes'] = ($clean['notes'] ?? '') !== '' ? $clean['notes'] : null;

        $countries = [];
        foreach ($this->splitList((string) ($data['preferred_countries'] ?? '')) as $code) {
            $code = strtoupper($code);
            if (!preg_match('/^[A-Z]{2}$/', $code)) {
                throw new ValidationException(['preferred_countries' => ['Use 2-letter country codes, comma-separated (e.g. AE, SA).']]);
            }
            $countries[] = $code;
        }

        $titles = [];
        foreach ($this->splitList((string) ($data['preferred_job_titles'] ?? '')) as $title) {
            if (mb_strlen($title) > 120) {
                throw new ValidationException(['preferred_job_titles' => ['Each job title must be 120 characters or fewer.']]);
            }
            $titles[] = $title;
        }

        $clean['preferred_countries'] = array_slice(array_values(array_unique($countries)), 0, 20);
        $clean['preferred_job_titles'] = array_slice(array_values(array_unique($titles)), 0, 20);

        return $clean;
    }

    /** @return list<string> */
    private function splitList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }
}
