<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\HtmlSanitizer;

/**
 * Tour package shape validation. The inclusions / exclusions / terms arrive as
 * plain text and are converted to escaped paragraph HTML here, so what reaches
 * the `*_html` columns is never raw user markup.
 */
final class TourPackageValidator
{
    private const RULES = [
        'name'              => 'required|string|max:180',
        'destination'       => 'required|string|max:120',
        'duration_days'     => 'nullable|integer|min:1|max:365',
        'duration_nights'   => 'nullable|integer|min:0|max:365',
        'start_location'    => 'nullable|string|max:120',
        'price'             => 'nullable|numeric|min:0|max:9999999999999',
        'currency'          => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
        'hotel_summary'     => 'nullable|string|max:255',
        'transport_summary' => 'nullable|string|max:255',
        'meals_summary'     => 'nullable|string|max:255',
        'inclusions'        => 'nullable|string|max:20000',
        'exclusions'        => 'nullable|string|max:20000',
        'terms'             => 'nullable|string|max:20000',
    ];

    private const ITEM_RULES = [
        'day_no'      => 'nullable|integer|min:1|max:365',
        'title'       => 'required|string|max:180',
        'description' => 'nullable|string|max:2000',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> DB-ready columns (inclusions → inclusions_html, …)
     */
    public function validate(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::RULES, ['currency.regex' => 'Use a three-letter currency code such as INR.'])->validated();

        $out = [
            'name'              => (string) $clean['name'],
            'destination'       => (string) $clean['destination'],
            'duration_days'     => $this->int($clean['duration_days'] ?? null),
            'duration_nights'   => $this->int($clean['duration_nights'] ?? null),
            'start_location'    => $this->blank($clean['start_location'] ?? null),
            'price'             => $this->money($clean['price'] ?? null),
            'currency'          => $this->blank($clean['currency'] ?? null) !== null ? strtoupper((string) $clean['currency']) : null,
            'hotel_summary'     => $this->blank($clean['hotel_summary'] ?? null),
            'transport_summary' => $this->blank($clean['transport_summary'] ?? null),
            'meals_summary'     => $this->blank($clean['meals_summary'] ?? null),
        ];

        if ($out['price'] !== null && $out['currency'] === null) {
            throw new ValidationException(['currency' => ['Give the currency of the price.']]);
        }
        if ($out['duration_days'] !== null && $out['duration_nights'] !== null && $out['duration_nights'] > $out['duration_days']) {
            throw new ValidationException(['duration_nights' => ['A tour cannot have more nights than days.']]);
        }

        foreach (['inclusions', 'exclusions', 'terms'] as $field) {
            $text = (string) ($clean[$field] ?? '');
            $out[$field . '_html'] = $text !== '' ? HtmlSanitizer::fromPlainText($text) : null;
        }

        return $out;
    }

    /**
     * One itinerary line.
     *
     * @param array<string,mixed> $data
     * @return array{day_no:?int,title:string,description:?string}
     */
    public function item(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::ITEM_RULES)->validated();

        return [
            'day_no'      => $this->int($clean['day_no'] ?? null),
            'title'       => (string) $clean['title'],
            'description' => $this->blank($clean['description'] ?? null),
        ];
    }

    private function blank(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? (string) $v : null;
    }

    private function int(mixed $v): ?int
    {
        return $v !== null && $v !== '' ? (int) $v : null;
    }

    private function money(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? number_format((float) $v, 2, '.', '') : null;
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
