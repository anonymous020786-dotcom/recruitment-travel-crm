<?php

declare(strict_types=1);

namespace App\Validators;

final class EmployerContactValidator
{
    private const RULES = [
        'name'        => 'required|string|max:120',
        'designation' => 'nullable|string|max:120',
        'email'       => 'nullable|email|max:180',
        'phone'       => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
        'is_primary'  => 'nullable|boolean',
    ];

    private const MESSAGES = ['phone.regex' => 'Phone must be 7–30 digits (spaces, +, - and brackets allowed).'];

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

        $clean = Validator::make($data, self::RULES, self::MESSAGES)->validated();

        foreach (['designation', 'email', 'phone'] as $k) {
            $clean[$k] = ($clean[$k] ?? '') !== '' ? $clean[$k] : null;
        }
        if ($clean['email'] !== null) {
            $clean['email'] = strtolower($clean['email']);
        }
        $clean['is_primary'] = in_array($clean['is_primary'] ?? false, [true, 1, '1', 'on', 'true'], true);

        return $clean;
    }
}
