<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Field-shape validation for editing a candidate's profile. The profile spans
 * two tables (persons + candidates); referential checks (counselor in branch)
 * and the transactional two-table write are the service's job.
 */
final class CandidateValidator
{
    private const RULES = [
        // persons
        'full_name'         => 'required|string|max:150',
        'gender'            => 'nullable|in:male,female,other,undisclosed',
        'date_of_birth'     => 'nullable|date|before_today',
        'primary_phone'     => 'required|string|min:7|max:30|regex:/^[0-9+()\-\s]{7,30}$/',
        'alternate_phone'   => 'nullable|string|max:30|regex:/^[0-9+()\-\s]{7,30}$/',
        'email'             => 'nullable|email|max:180',
        'nationality'       => 'nullable|string|size:2|alpha',
        'city'              => 'nullable|string|max:90',
        'state'             => 'nullable|string|max:90',
        'country'           => 'nullable|string|size:2|alpha',
        // candidates
        'marital_status'        => 'nullable|in:single,married,divorced,widowed',
        'current_country'       => 'nullable|string|size:2|alpha',
        'highest_qualification' => 'nullable|string|max:120',
        'total_experience_years' => 'nullable|numeric|min:0|max:60',
    ];

    private const MESSAGES = [
        'primary_phone.regex'   => 'Phone must be 7–30 digits (spaces, +, - and brackets allowed).',
        'alternate_phone.regex' => 'Alternate phone must be 7–30 digits.',
        'date_of_birth.before_today' => 'Date of birth must be in the past.',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> validated + normalised
     */
    public function validate(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }

        $clean = Validator::make($data, self::RULES, self::MESSAGES)
            ->rule('before_today', function ($value): bool|string {
                if ($value === null || $value === '') {
                    return true;
                }
                $ts = strtotime((string) $value);
                return $ts !== false && $ts < strtotime('today') ? true : 'must be a past date';
            })
            ->validated();

        foreach (['nationality', 'country', 'current_country'] as $field) {
            if (isset($clean[$field])) {
                $clean[$field] = strtoupper((string) $clean[$field]);
            }
        }

        return $clean;
    }
}
