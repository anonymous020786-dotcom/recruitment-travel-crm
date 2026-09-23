<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\HtmlSanitizer;

/**
 * Job posting shape validation. The description arrives as plain text and is
 * converted to escaped paragraph HTML here, so what reaches `description_html`
 * is never raw user markup.
 */
final class JobValidator
{
    private const RULES = [
        'title'                    => 'required|string|max:160',
        'country'                  => 'required|string|size:2|alpha',
        'city'                     => 'nullable|string|max:90',
        'vacancies'                => 'required|integer|min:1|max:60000',
        'salary_min'               => 'nullable|numeric|min:0|max:99999999',
        'salary_max'               => 'nullable|numeric|min:0|max:99999999',
        'currency'                 => 'nullable|string|size:3|alpha',
        'experience_required'      => 'nullable|string|max:120',
        'qualification'            => 'nullable|string|max:120',
        'age_min'                  => 'nullable|integer|min:14|max:80',
        'age_max'                  => 'nullable|integer|min:14|max:80',
        'gender_requirement'       => 'nullable|in:any,male,female',
        'accommodation'            => 'nullable|in:none,provided,allowance',
        'food'                     => 'nullable|in:none,provided,allowance',
        'transport'                => 'nullable|in:none,provided,allowance',
        'working_hours'            => 'nullable|string|max:60',
        'overtime'                 => 'nullable|string|max:120',
        'contract_duration_months' => 'nullable|integer|min:1|max:120',
        'interview_type'           => 'nullable|in:in_person,video,telephonic,cv_selection,client_visit',
        'deadline'                 => 'nullable|date',
        'description'              => 'nullable|string|max:20000',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> DB-ready columns (description → description_html)
     */
    public function validate(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }

        $clean = Validator::make($data, self::RULES)->validated();

        $clean['country'] = strtoupper((string) $clean['country']);
        $clean['vacancies'] = (int) $clean['vacancies'];
        foreach (['city', 'experience_required', 'qualification', 'working_hours', 'overtime', 'interview_type', 'deadline'] as $k) {
            $clean[$k] = ($clean[$k] ?? '') !== '' ? $clean[$k] : null;
        }
        foreach (['salary_min', 'salary_max'] as $k) {
            $clean[$k] = ($clean[$k] ?? '') !== '' ? (float) $clean[$k] : null;
        }
        foreach (['age_min', 'age_max', 'contract_duration_months'] as $k) {
            $clean[$k] = ($clean[$k] ?? '') !== '' ? (int) $clean[$k] : null;
        }
        $clean['currency'] = ($clean['currency'] ?? '') !== '' ? strtoupper((string) $clean['currency']) : null;
        foreach (['gender_requirement' => 'any', 'accommodation' => 'none', 'food' => 'none', 'transport' => 'none'] as $k => $default) {
            $clean[$k] = ($clean[$k] ?? '') !== '' ? $clean[$k] : $default;
        }

        if ($clean['salary_min'] !== null && $clean['salary_max'] !== null && $clean['salary_max'] < $clean['salary_min']) {
            throw new ValidationException(['salary_max' => ['Maximum salary cannot be below the minimum.']]);
        }
        if (($clean['salary_min'] !== null || $clean['salary_max'] !== null) && $clean['currency'] === null) {
            throw new ValidationException(['currency' => ['Give a currency (e.g. AED) when a salary is set.']]);
        }
        if ($clean['age_min'] !== null && $clean['age_max'] !== null && $clean['age_max'] < $clean['age_min']) {
            throw new ValidationException(['age_max' => ['Maximum age cannot be below the minimum.']]);
        }

        $description = (string) ($clean['description'] ?? '');
        unset($clean['description']);
        $clean['description_html'] = $description !== '' ? HtmlSanitizer::fromPlainText($description) : null;

        return $clean;
    }
}
