<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/** Validates the invoice form: header fields plus repeating line rows (`line_description[]`, `line_quantity[]`, `line_unit_price[]`). */
final class InvoiceValidator
{
    public const MAX_LINES = 40;
    private const MAX_QUANTITY = 100000;
    private const MAX_UNIT_PRICE = 99999999;

    private const RULES = [
        'currency'       => ['nullable', 'regex:/^[A-Za-z]{3}$/'],
        'due_on'         => 'nullable|string|max:10',
        'discount_total' => 'nullable|numeric|min:0|max:9999999999999',
        'tax_total'      => 'nullable|numeric|min:0|max:9999999999999',
        'notes'          => 'nullable|string|max:500',
    ];

    private const TARGET_RULES = [
        'type'      => 'required|in:application,tour_booking',
        'reference' => 'required|string|min:3|max:24',
    ];

    /**
     * What the invoice is for: an application or a tour booking, named by its number.
     *
     * @param array<string,mixed> $data
     * @return array{type:string,reference:string}
     */
    public function target(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::TARGET_RULES, ['reference.required' => 'Enter the application or booking number.'])->validated();

        return ['type' => (string) $clean['type'], 'reference' => strtoupper((string) $clean['reference'])];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{currency:?string,due_on:?string,discount_total:string,tax_total:string,notes:?string,lines:list<array{description:string,quantity:string,unit_price:string}>}
     */
    public function invoice(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::RULES, ['currency.regex' => 'Use a three-letter currency code such as INR.'])->validated();

        $due = $this->date($clean['due_on'] ?? null);
        $currency = ($clean['currency'] ?? '') !== '' ? strtoupper((string) $clean['currency']) : null;

        return [
            'currency'       => $currency,
            'due_on'         => $due,
            'discount_total' => $this->money($clean['discount_total'] ?? null),
            'tax_total'      => $this->money($clean['tax_total'] ?? null),
            'notes'          => ($clean['notes'] ?? '') !== '' ? (string) $clean['notes'] : null,
            'lines'          => $this->lines($data),
        ];
    }

    /**
     * Blank rows are ignored; a row that has any content must be complete and sensible.
     *
     * @param array<string,mixed> $data
     * @return list<array{description:string,quantity:string,unit_price:string}>
     */
    private function lines(array $data): array
    {
        $desc = (array) ($data['line_description'] ?? []);
        $qty = (array) ($data['line_quantity'] ?? []);
        $price = (array) ($data['line_unit_price'] ?? []);

        $out = [];
        foreach ($desc as $i => $d) {
            $d = trim((string) $d);
            $q = trim((string) ($qty[$i] ?? ''));
            $p = trim((string) ($price[$i] ?? ''));
            if ($d === '' && $p === '') {
                continue; // an untouched row (quantity alone is just the default "1")
            }
            $row = (int) $i + 1;
            if ($d === '') {
                throw new ValidationException(['lines' => ["Line {$row} needs a description."]]);
            }
            if (mb_strlen($d) > 255) {
                throw new ValidationException(['lines' => ["Line {$row}: the description is too long."]]);
            }
            $q = $q === '' ? '1' : $q;
            if (!is_numeric($q) || (float) $q <= 0 || (float) $q > self::MAX_QUANTITY) {
                throw new ValidationException(['lines' => ["Line {$row}: quantity must be between 0.01 and " . self::MAX_QUANTITY . '.']]);
            }
            if ($p === '' || !is_numeric($p) || (float) $p < 0 || (float) $p > self::MAX_UNIT_PRICE) {
                throw new ValidationException(['lines' => ["Line {$row}: enter a price between 0 and " . self::MAX_UNIT_PRICE . '.']]);
            }
            $out[] = ['description' => $d, 'quantity' => number_format((float) $q, 2, '.', ''), 'unit_price' => number_format((float) $p, 2, '.', '')];
        }
        if (count($out) > self::MAX_LINES) {
            throw new ValidationException(['lines' => ['An invoice can have at most ' . self::MAX_LINES . ' lines.']]);
        }

        return $out;
    }

    private function money(mixed $v): string
    {
        return $v !== null && $v !== '' ? number_format((float) $v, 2, '.', '') : '0.00';
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new ValidationException(['due_on' => ['Use a valid date.']]);
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
