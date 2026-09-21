<?php

declare(strict_types=1);

namespace App\Validators;

final class CandidateEducationValidator
{
    private const RULES = [
        'level'            => 'required|string|max:60',
        'institution'      => 'nullable|string|max:180',
        'board_university' => 'nullable|string|max:180',
        'field_of_study'   => 'nullable|string|max:120',
        'start_year'       => 'nullable|integer|min:1950|max:2100',
        'end_year'         => 'nullable|integer|min:1950|max:2100',
        'grade'            => 'nullable|string|max:40',
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

        foreach (['start_year', 'end_year'] as $k) {
            if (array_key_exists($k, $clean)) {
                $clean[$k] = ($clean[$k] === null || $clean[$k] === '') ? null : (int) $clean[$k];
            }
        }

        if (($clean['start_year'] ?? null) !== null && ($clean['end_year'] ?? null) !== null && $clean['end_year'] < $clean['start_year']) {
            throw new \App\Exceptions\ValidationException(['end_year' => ['End year cannot be before the start year.']]);
        }

        return $clean;
    }
}
