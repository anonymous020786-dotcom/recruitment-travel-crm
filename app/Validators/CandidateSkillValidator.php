<?php

declare(strict_types=1);

namespace App\Validators;

final class CandidateSkillValidator
{
    private const RULES = [
        'skill_name'  => 'required|string|max:80',
        'category'    => 'nullable|string|max:60',
        'proficiency' => 'nullable|in:basic,intermediate,advanced,expert',
        'years'       => 'nullable|numeric|min:0|max:60',
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

        $clean['proficiency'] = ($clean['proficiency'] ?? '') !== '' ? $clean['proficiency'] : 'intermediate';
        $clean['years'] = ($clean['years'] ?? '') !== '' ? (float) $clean['years'] : null;
        $clean['category'] = ($clean['category'] ?? '') !== '' ? $clean['category'] : null;

        return $clean;
    }
}
