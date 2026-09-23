<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/** Validates the schedule / reschedule form and the outcome form of an interview. */
final class InterviewValidator
{
    private const SCHEDULE_RULES = [
        'type'           => 'required|in:in_person,video,telephonic,client_visit',
        'scheduled_date' => 'required|date',
        'scheduled_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        'location'       => 'nullable|string|max:200',
        'meeting_link'   => 'nullable|url|max:255',
        'interviewer'    => 'nullable|string|max:160',
        'notes'          => 'nullable|string|max:2000',
    ];

    private const OUTCOME_RULES = [
        'outcome'  => 'required|in:selected,rejected,hold,no_show',
        'feedback' => 'nullable|string|max:4000',
    ];

    private const MESSAGES = [
        'scheduled_time.regex' => 'Use a 24-hour time like 14:30.',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> columns ready for the interviews table
     */
    public function schedule(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::SCHEDULE_RULES, self::MESSAGES)->validated();

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $clean['scheduled_date']);
        if ($date === false || $date->format('Y-m-d') !== $clean['scheduled_date']) {
            throw new ValidationException(['scheduled_date' => ['Use a valid date.']]);
        }
        if ($clean['scheduled_date'] < gmdate('Y-m-d')) {
            throw new ValidationException(['scheduled_date' => ['Choose today or a future date.']]);
        }

        foreach (['scheduled_time', 'location', 'meeting_link', 'interviewer', 'notes'] as $k) {
            $clean[$k] = ($clean[$k] ?? '') !== '' ? $clean[$k] : null;
        }
        if ($clean['scheduled_time'] !== null) {
            $clean['scheduled_time'] .= ':00';
        }
        if ($clean['meeting_link'] !== null && !preg_match('#^https?://#i', (string) $clean['meeting_link'])) {
            throw new ValidationException(['meeting_link' => ['The meeting link must start with http:// or https://.']]);
        }
        if ($clean['type'] === 'video' && $clean['meeting_link'] === null) {
            throw new ValidationException(['meeting_link' => ['A video interview needs a meeting link.']]);
        }
        if (in_array($clean['type'], ['in_person', 'client_visit'], true) && $clean['location'] === null) {
            throw new ValidationException(['location' => ['Say where the interview will take place.']]);
        }

        return $clean;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{outcome:string,feedback:?string}
     */
    public function outcome(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::OUTCOME_RULES)->validated();
        $clean['feedback'] = ($clean['feedback'] ?? '') !== '' ? $clean['feedback'] : null;

        if ($clean['outcome'] === 'rejected' && $clean['feedback'] === null) {
            throw new ValidationException(['feedback' => ['Give feedback when rejecting a candidate.']]);
        }

        return ['outcome' => $clean['outcome'], 'feedback' => $clean['feedback']];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function trim(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }

        return $data;
    }
}
