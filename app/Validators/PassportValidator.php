<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

final class PassportValidator
{
    private const RULES = [
        'passport_number' => 'required|string|max:30|regex:/^[A-Za-z0-9]{4,30}$/',
        'issue_date'      => 'nullable|date',
        'expiry_date'     => 'nullable|date',
        'place_of_issue'  => 'nullable|string|max:120',
        'nationality'     => 'nullable|string|size:2|alpha',
        'is_primary'      => 'nullable|boolean',
        'held_by'         => 'nullable|in:candidate,agency,employer,embassy',
    ];

    private const MESSAGES = [
        'passport_number.regex' => 'Use letters and numbers only (4–30 characters).',
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

        $clean = Validator::make($data, self::RULES, self::MESSAGES)->validated();

        $clean['passport_number'] = strtoupper((string) $clean['passport_number']);
        $clean['nationality'] = ($clean['nationality'] ?? '') !== '' ? strtoupper((string) $clean['nationality']) : null;
        $clean['place_of_issue'] = ($clean['place_of_issue'] ?? '') !== '' ? $clean['place_of_issue'] : null;
        $clean['issue_date'] = ($clean['issue_date'] ?? '') !== '' ? $clean['issue_date'] : null;
        $clean['expiry_date'] = ($clean['expiry_date'] ?? '') !== '' ? $clean['expiry_date'] : null;
        $clean['held_by'] = ($clean['held_by'] ?? '') !== '' ? $clean['held_by'] : 'candidate';
        $clean['is_primary'] = in_array($clean['is_primary'] ?? false, [true, 1, '1', 'on', 'true'], true);

        if ($clean['issue_date'] !== null && $clean['expiry_date'] !== null && $clean['expiry_date'] < $clean['issue_date']) {
            throw new ValidationException(['expiry_date' => ['Expiry date cannot be before the issue date.']]);
        }

        return $clean;
    }
}
