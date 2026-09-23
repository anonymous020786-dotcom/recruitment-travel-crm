<?php

declare(strict_types=1);

namespace App\Validators;

final class JobRequirementValidator
{
    private const RULES = [
        'label'        => 'required|string|max:120',
        'is_mandatory' => 'nullable|boolean',
        'weight'       => 'nullable|integer|min:1|max:10',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array{label:string,is_mandatory:bool,weight:int}
     */
    public function validate(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }

        $clean = Validator::make($data, self::RULES)->validated();

        return [
            'label'        => (string) $clean['label'],
            'is_mandatory' => in_array($clean['is_mandatory'] ?? true, [true, 1, '1', 'on', 'true'], true),
            'weight'       => ($clean['weight'] ?? '') !== '' ? (int) $clean['weight'] : 1,
        ];
    }
}
