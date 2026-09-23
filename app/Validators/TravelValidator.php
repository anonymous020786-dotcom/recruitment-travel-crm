<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/** Validates the flight, departure, arrival, placement and travel-profile forms. */
final class TravelValidator
{
    /** Departure / arrival times are entered in local time; allow for the furthest-ahead time zone when rejecting "future" values. */
    private const TZ_SLACK_HOURS = 14;

    private const FLIGHT_RULES = [
        'status'            => 'nullable|in:planned,booked,issued',
        'pnr'               => ['nullable', 'regex:/^[A-Za-z0-9]{5,20}$/'],
        'airline'           => 'nullable|string|max:120',
        'flight_number'     => 'nullable|string|max:20',
        'departure_airport' => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
        'arrival_airport'   => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
        'departure_at'      => 'nullable|string|max:20',
        'arrival_at'        => 'nullable|string|max:20',
        'baggage_allowance' => 'nullable|string|max:60',
        'ticket_price'      => 'nullable|numeric|min:0|max:9999999999999',
        'currency'          => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
        'notes'             => 'nullable|string|max:2000',
    ];

    private const FLIGHT_MESSAGES = [
        'pnr.regex'               => 'The booking reference (PNR) is 5–20 letters and digits.',
        'departure_airport.regex' => 'Use a three-letter airport code such as DEL.',
        'arrival_airport.regex'   => 'Use a three-letter airport code such as DXB.',
        'currency.regex'          => 'Use a three-letter currency code such as INR.',
    ];

    private const PLACEMENT_RULES = [
        'placed_on'      => 'required|date',
        'monthly_salary' => 'nullable|numeric|min:0|max:9999999999999',
        'currency'       => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
        'contract_end'   => 'nullable|date',
    ];

    /**
     * Book / edit a flight.
     *
     * @param array<string,mixed> $data
     * @return array{status:string,pnr:?string,airline:?string,flight_number:?string,departure_airport:?string,arrival_airport:?string,departure_at:?string,arrival_at:?string,baggage_allowance:?string,ticket_price:?string,currency:?string,notes:?string}
     */
    public function flight(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::FLIGHT_RULES, self::FLIGHT_MESSAGES)->validated();

        $out = [
            'status'            => (string) (($clean['status'] ?? null) ?: 'planned'),
            'pnr'               => $this->upper($clean['pnr'] ?? null),
            'airline'           => $this->blank($clean['airline'] ?? null),
            'flight_number'     => $this->upper($clean['flight_number'] ?? null),
            'departure_airport' => $this->upper($clean['departure_airport'] ?? null),
            'arrival_airport'   => $this->upper($clean['arrival_airport'] ?? null),
            'departure_at'      => $this->dateTime($clean['departure_at'] ?? null, 'departure_at'),
            'arrival_at'        => $this->dateTime($clean['arrival_at'] ?? null, 'arrival_at'),
            'baggage_allowance' => $this->blank($clean['baggage_allowance'] ?? null),
            'ticket_price'      => $this->blank($clean['ticket_price'] ?? null),
            'currency'          => $this->upper($clean['currency'] ?? null),
            'notes'             => $this->blank($clean['notes'] ?? null),
        ];

        if ($out['ticket_price'] !== null && $out['currency'] === null) {
            throw new ValidationException(['currency' => ['Give the currency of the ticket price.']]);
        }
        if ($out['departure_at'] !== null && $out['arrival_at'] !== null && $out['arrival_at'] <= $out['departure_at']) {
            throw new ValidationException(['arrival_at' => ['The flight must arrive after it departs.']]);
        }
        if (in_array($out['status'], ['booked', 'issued'], true)) {
            if ($out['pnr'] === null) {
                throw new ValidationException(['pnr' => ['A booked or issued ticket needs its booking reference (PNR).']]);
            }
            if ($out['departure_at'] === null) {
                throw new ValidationException(['departure_at' => ['A booked or issued ticket needs its departure date and time.']]);
            }
        }

        return $out;
    }

    /**
     * Departure or arrival form: an optional moment (defaults to now) and notes.
     *
     * @param array<string,mixed> $data
     * @return array{at:string,notes:?string}
     */
    public function movement(array $data, string $field): array
    {
        $data = $this->trim($data);
        $at = $this->dateTime($data[$field] ?? null, $field) ?? gmdate('Y-m-d H:i:s');

        $limit = gmdate('Y-m-d H:i:s', time() + self::TZ_SLACK_HOURS * 3600);
        if ($at > $limit) {
            throw new ValidationException([$field => ['That time is in the future.']]);
        }
        $notes = $this->blank($data['notes'] ?? null);
        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw new ValidationException(['notes' => ['Notes may be at most 2000 characters.']]);
        }

        return ['at' => $at, 'notes' => $notes];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{placed_on:string,monthly_salary:?string,currency:?string,contract_end:?string}
     */
    public function placement(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::PLACEMENT_RULES, self::FLIGHT_MESSAGES)->validated();

        $placedOn = $this->date($clean['placed_on'], 'placed_on');
        $end = $this->date($clean['contract_end'] ?? null, 'contract_end');
        $salary = $this->blank($clean['monthly_salary'] ?? null);
        $currency = $this->upper($clean['currency'] ?? null);

        if ($placedOn > gmdate('Y-m-d', time() + self::TZ_SLACK_HOURS * 3600)) {
            throw new ValidationException(['placed_on' => ['The placement date cannot be in the future.']]);
        }
        if ($end !== null && $end <= $placedOn) {
            throw new ValidationException(['contract_end' => ['The contract must end after the placement date.']]);
        }
        if ($salary !== null && $currency === null) {
            throw new ValidationException(['currency' => ['Give the currency of the salary.']]);
        }

        return ['placed_on' => $placedOn, 'monthly_salary' => $salary, 'currency' => $currency, 'contract_end' => $end];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{preferred_departure_city:?string,notes:?string}
     */
    public function profile(array $data): array
    {
        $clean = Validator::make($this->trim($data), [
            'preferred_departure_city' => 'nullable|string|max:90',
            'notes'                    => 'nullable|string|max:2000',
        ])->validated();

        return [
            'preferred_departure_city' => $this->blank($clean['preferred_departure_city'] ?? null),
            'notes'                    => $this->blank($clean['notes'] ?? null),
        ];
    }

    private function blank(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? (string) $v : null;
    }

    private function upper(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? strtoupper((string) $v) : null;
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

    /** Accepts the browser's `2026-09-30T14:05` as well as `2026-09-30 14:05[:00]`. */
    private function dateTime(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i:s'] as $format) {
            $d = \DateTimeImmutable::createFromFormat($format, (string) $v);
            if ($d !== false && $d->format(ltrim($format, '!')) === $v) {
                return $d->format('Y-m-d H:i:s');
            }
        }

        throw new ValidationException([$field => ['Use a valid date and time.']]);
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
