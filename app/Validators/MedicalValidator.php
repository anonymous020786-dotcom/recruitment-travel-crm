<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/** Validates the book/reschedule, attended and result forms of a medical record. */
final class MedicalValidator
{
    private const BOOK_RULES = [
        'medical_center'   => 'nullable|string|max:180',
        'appointment_date' => 'nullable|date',
        'notes'            => 'nullable|string|max:2000',
    ];

    private const ATTENDED_RULES = ['medical_date' => 'required|date'];

    private const RESULT_RULES = [
        'result'      => 'required|in:fit,unfit,retest',
        'report_date' => 'nullable|date',
        'expires_at'  => 'nullable|date',
        'notes'       => 'nullable|string|max:2000',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array{medical_center:?string,appointment_date:?string,notes:?string}
     */
    public function book(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::BOOK_RULES)->validated();
        $clean['medical_center'] = $this->blank($clean['medical_center'] ?? null);
        $clean['notes'] = $this->blank($clean['notes'] ?? null);
        $clean['appointment_date'] = $this->date($clean['appointment_date'] ?? null, 'appointment_date');

        if ($clean['appointment_date'] !== null && $clean['appointment_date'] < gmdate('Y-m-d')) {
            throw new ValidationException(['appointment_date' => ['Choose today or a future date.']]);
        }

        return $clean;
    }

    /** @param array<string,mixed> $data */
    public function attended(array $data): string
    {
        $clean = Validator::make($this->trim($data), self::ATTENDED_RULES)->validated();
        $date = $this->date($clean['medical_date'], 'medical_date');
        if ($date > gmdate('Y-m-d')) {
            throw new ValidationException(['medical_date' => ['The medical cannot be in the future.']]);
        }

        return (string) $date;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{result:string,report_date:string,expires_at:?string,notes:?string}
     */
    public function result(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::RESULT_RULES)->validated();
        $report = $this->date($clean['report_date'] ?? null, 'report_date') ?? gmdate('Y-m-d');
        $expires = $this->date($clean['expires_at'] ?? null, 'expires_at');

        if ($report > gmdate('Y-m-d')) {
            throw new ValidationException(['report_date' => ['The report date cannot be in the future.']]);
        }
        if ($expires !== null && $expires <= $report) {
            throw new ValidationException(['expires_at' => ['The certificate must expire after the report date.']]);
        }

        return [
            'result'      => (string) $clean['result'],
            'report_date' => $report,
            'expires_at'  => $clean['result'] === 'fit' ? $expires : null,
            'notes'       => $this->blank($clean['notes'] ?? null),
        ];
    }

    private function blank(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? (string) $v : null;
    }

    private function date(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new ValidationException([$field => ['Use a valid date.']]);
        }

        return $d->format('Y-m-d');
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
