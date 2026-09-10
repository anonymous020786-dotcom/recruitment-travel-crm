<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Field-shape validation for lead create/update. Referential checks
 * (source exists, assignee in branch) and business rules (duplicates, status
 * transitions) are the service's job.
 */
final class LeadValidator
{
    private const RULES = [
        'name'               => 'required|string|max:150',
        'phone'              => 'required|string|min:7|max:30|regex:/^[0-9+()\-\s]{7,30}$/',
        'alternate_phone'    => 'nullable|string|max:30|regex:/^[0-9+()\-\s]{7,30}$/',
        'email'              => 'nullable|email|max:180',
        'gender'             => 'nullable|in:male,female,other,undisclosed',
        'date_of_birth'      => 'nullable|date|before_today',
        'city'               => 'nullable|string|max:90',
        'state'              => 'nullable|string|max:90',
        'source_id'          => 'nullable|integer',
        'campaign'           => 'nullable|string|max:120',
        'interested_country' => 'nullable|string|size:2|alpha',
        'interested_job'     => 'nullable|string|max:120',
        'experience_years'   => 'nullable|numeric|min:0|max:60',
        'qualification'      => 'nullable|string|max:120',
        'salary_expectation' => 'nullable|numeric|min:0|max:99999999',
        'salary_currency'    => 'nullable|string|size:3|alpha',
        'priority'           => 'required|in:low,medium,high,urgent',
        'assigned_to'        => 'nullable|integer',
        'notes'              => 'nullable|string|max:5000',
    ];

    private const MESSAGES = [
        'phone.regex'           => 'Phone must be 7–30 digits (spaces, +, - and brackets allowed).',
        'alternate_phone.regex' => 'Alternate phone must be 7–30 digits.',
        'interested_country'    => 'Country must be a 2-letter code (e.g. AE).',
        'date_of_birth.before_today' => 'Date of birth must be in the past.',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> validated + normalised
     */
    public function validate(array $data, string $context = 'create'): array
    {
        $rules = self::RULES;
        if ($context === 'update') {
            // Same shape; nothing relaxed for now.
        }

        // Trim every string BEFORE validating so " a@b.com " passes the email rule.
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }

        $validator = Validator::make($data, $rules, self::MESSAGES)
            ->rule('before_today', function ($value): bool|string {
                if ($value === null || $value === '') {
                    return true;
                }
                $ts = strtotime((string) $value);
                return $ts !== false && $ts < strtotime('today') ? true : 'must be a past date';
            });

        $clean = $validator->validated();

        // Normalise.
        foreach (['name', 'city', 'state', 'campaign', 'qualification', 'interested_job'] as $k) {
            if (isset($clean[$k])) {
                $clean[$k] = trim((string) $clean[$k]) ?: null;
            }
        }
        if (isset($clean['email'])) {
            $clean['email'] = strtolower(trim((string) $clean['email'])) ?: null;
        }
        foreach (['interested_country', 'salary_currency'] as $k) {
            if (isset($clean[$k]) && $clean[$k] !== null) {
                $clean[$k] = strtoupper((string) $clean[$k]);
            }
        }
        foreach (['phone', 'alternate_phone'] as $k) {
            if (isset($clean[$k]) && $clean[$k] !== null) {
                $clean[$k] = preg_replace('/\s+/', ' ', trim((string) $clean[$k]));
            }
        }
        foreach (['source_id', 'assigned_to'] as $k) {
            if (array_key_exists($k, $clean)) {
                $clean[$k] = ($clean[$k] === null || $clean[$k] === '') ? null : (int) $clean[$k];
            }
        }

        return $clean;
    }
}
