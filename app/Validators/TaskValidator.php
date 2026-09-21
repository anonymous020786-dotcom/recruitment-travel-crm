<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

final class TaskValidator
{
    private const RULES = [
        'title'       => 'required|string|max:200',
        'description' => 'nullable|string|max:2000',
        'priority'    => 'nullable|in:low,medium,high,urgent',
        'due_date'    => 'nullable|date',
        // Array form, not a pipe-delimited string: the regex's own `|` alternation
        // would otherwise be split apart by the rule-string parser.
        'due_time'    => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        'assigned_to' => 'required|integer',
    ];

    private const MESSAGES = [
        'due_time.regex' => 'Use a 24-hour time like 14:30.',
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

        $clean['priority'] = ($clean['priority'] ?? '') !== '' ? $clean['priority'] : 'medium';
        $clean['description'] = ($clean['description'] ?? '') !== '' ? $clean['description'] : null;
        $clean['due_date'] = ($clean['due_date'] ?? '') !== '' ? $clean['due_date'] : null;
        $clean['due_time'] = ($clean['due_time'] ?? '') !== '' ? $clean['due_time'] . ':00' : null;
        $clean['assigned_to'] = (int) $clean['assigned_to'];

        if ($clean['due_date'] !== null && $clean['due_date'] < gmdate('Y-m-d')) {
            throw new ValidationException(['due_date' => ['Choose today or a future date.']]);
        }

        return $clean;
    }
}
