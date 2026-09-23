<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/** Validates the tour booking forms: the customer block (create only) and the trip details. */
final class TourBookingValidator
{
    private const CUSTOMER_RULES = [
        'customer_name'  => 'required|string|min:2|max:150',
        'customer_phone' => 'required|string|min:7|max:30|regex:/^[0-9+()\-\s]{7,30}$/',
        'customer_email' => 'nullable|email|max:180',
    ];

    private const TRIP_RULES = [
        'package'      => 'nullable|ulid',
        'travel_date'  => 'nullable|string|max:10',
        'return_date'  => 'nullable|string|max:10',
        'adults'       => 'required|integer|min:1|max:200',
        'children'     => 'nullable|integer|min:0|max:200',
        'total_amount' => 'nullable|numeric|min:0|max:9999999999999',
        'currency'     => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
        'assigned_to'  => 'nullable|integer|min:1',
        'notes'        => 'nullable|string|max:5000',
    ];

    private const MESSAGES = [
        'customer_phone.regex' => 'Phone must be 7–30 digits (spaces, +, - and brackets allowed).',
        'currency.regex'       => 'Use a three-letter currency code such as INR.',
        'package.ulid'         => 'Choose a package from the list.',
    ];

    /**
     * The customer block of the create form.
     *
     * @param array<string,mixed> $data
     * @return array{full_name:string,primary_phone:string,email:?string}
     */
    public function customer(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::CUSTOMER_RULES, self::MESSAGES)->validated();
        $email = $this->blank($clean['customer_email'] ?? null);

        return [
            'full_name'     => preg_replace('/\s+/', ' ', (string) $clean['customer_name']) ?? (string) $clean['customer_name'],
            'primary_phone' => preg_replace('/\s+/', ' ', (string) $clean['customer_phone']) ?? (string) $clean['customer_phone'],
            'email'         => $email !== null ? strtolower($email) : null,
        ];
    }

    /**
     * The trip details (create and edit).
     *
     * @param array<string,mixed> $data
     * @return array{package:?string,travel_date:?string,return_date:?string,adults:int,children:int,total_amount:?string,currency:?string,assigned_to:?int,notes:?string}
     */
    public function trip(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::TRIP_RULES, self::MESSAGES)->validated();

        $travel = $this->date($clean['travel_date'] ?? null, 'travel_date');
        $return = $this->date($clean['return_date'] ?? null, 'return_date');
        if ($return !== null && $travel === null) {
            throw new ValidationException(['travel_date' => ['Give the travel date as well as the return date.']]);
        }
        if ($travel !== null && $return !== null && $return < $travel) {
            throw new ValidationException(['return_date' => ['The return date cannot be before the travel date.']]);
        }

        $amount = $this->blank($clean['total_amount'] ?? null);
        $currency = $this->blank($clean['currency'] ?? null);

        return [
            'package'      => $this->blank($clean['package'] ?? null),
            'travel_date'  => $travel,
            'return_date'  => $return,
            'adults'       => (int) $clean['adults'],
            'children'     => (int) ($clean['children'] ?? 0),
            'total_amount' => $amount !== null ? number_format((float) $amount, 2, '.', '') : null,
            'currency'     => $currency !== null ? strtoupper($currency) : null,
            'assigned_to'  => ($clean['assigned_to'] ?? '') !== '' ? (int) $clean['assigned_to'] : null,
            'notes'        => $this->blank($clean['notes'] ?? null),
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
