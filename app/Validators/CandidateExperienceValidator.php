<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

final class CandidateExperienceValidator
{
    private const RULES = [
        'employer_name'    => 'required|string|max:180',
        'job_title'        => 'required|string|max:120',
        'country'          => 'nullable|string|size:2|alpha',
        'start_date'       => 'nullable|date',
        'end_date'         => 'nullable|date',
        'is_current'       => 'nullable|boolean',
        'responsibilities' => 'nullable|string|max:2000',
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

        $clean['is_current'] = in_array($clean['is_current'] ?? false, [true, 1, '1', 'on', 'true'], true);
        if (isset($clean['country']) && $clean['country'] !== null) {
            $clean['country'] = strtoupper((string) $clean['country']);
        }
        if ($clean['is_current']) {
            $clean['end_date'] = null;
        }

        if (
            !$clean['is_current']
            && ($clean['start_date'] ?? null) !== null
            && ($clean['end_date'] ?? null) !== null
            && $clean['end_date'] < $clean['start_date']
        ) {
            throw new ValidationException(['end_date' => ['End date cannot be before the start date.']]);
        }

        return $clean;
    }
}
