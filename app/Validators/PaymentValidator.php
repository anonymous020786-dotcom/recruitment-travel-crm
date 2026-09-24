<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Models\Payment;

/** Validates the payment, allocation and reversal forms. */
final class PaymentValidator
{
    private const TZ_SLACK_HOURS = 14;

    private const RULES = [
        'amount'          => 'required|numeric|min:0.01|max:9999999999999',
        'method'          => 'required|in:cash,bank_transfer,upi,card,cheque,other',
        'reference'       => 'nullable|string|max:120',
        'paid_at'         => 'nullable|string|max:20',
        'notes'           => 'nullable|string|max:500',
        'idempotency_key' => ['nullable', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
    ];

    /**
     * @param array<string,mixed> $data
     * @return array{amount:string,method:string,reference:?string,paid_at:string,notes:?string,idempotency_key:?string}
     */
    public function payment(array $data): array
    {
        $clean = Validator::make($this->trim($data), self::RULES, [
            'amount.min' => 'The amount must be above zero.', 'idempotency_key.regex' => 'That submission token is not valid.',
        ])->validated();

        $method = (string) $clean['method'];
        $reference = ($clean['reference'] ?? '') !== '' ? (string) $clean['reference'] : null;
        if ($reference === null && in_array($method, Payment::NEEDS_REFERENCE, true)) {
            throw new ValidationException(['reference' => ['Enter the transaction / cheque reference for this payment method.']]);
        }

        $paidAt = $this->dateTime($clean['paid_at'] ?? null) ?? gmdate('Y-m-d H:i:s');
        if ($paidAt > gmdate('Y-m-d H:i:s', time() + self::TZ_SLACK_HOURS * 3600)) {
            throw new ValidationException(['paid_at' => ['A payment cannot be dated in the future.']]);
        }

        return [
            'amount'          => number_format((float) $clean['amount'], 2, '.', ''),
            'method'          => $method,
            'reference'       => $reference,
            'paid_at'         => $paidAt,
            'notes'           => ($clean['notes'] ?? '') !== '' ? (string) $clean['notes'] : null,
            'idempotency_key' => ($clean['idempotency_key'] ?? '') !== '' ? (string) $clean['idempotency_key'] : null,
        ];
    }

    /**
     * Apply (part of) a payment to an invoice named by number.
     *
     * @param array<string,mixed> $data
     * @return array{invoice:string,amount:string}
     */
    public function allocation(array $data): array
    {
        $clean = Validator::make($this->trim($data), [
            'invoice' => 'required|string|min:3|max:24',
            'amount'  => 'required|numeric|min:0.01|max:9999999999999',
        ], ['invoice.required' => 'Enter the invoice number.'])->validated();

        return ['invoice' => strtoupper((string) $clean['invoice']), 'amount' => number_format((float) $clean['amount'], 2, '.', '')];
    }

    /** @param array<string,mixed> $data */
    public function reason(array $data): string
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ValidationException(['reason' => ['Please give a reason.']]);
        }
        if (mb_strlen($reason) > 255) {
            throw new ValidationException(['reason' => ['The reason is too long (255 characters at most).']]);
        }

        return $reason;
    }

    private function dateTime(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $d = \DateTimeImmutable::createFromFormat($format, (string) $v);
            if ($d !== false && $d->format(ltrim($format, '!')) === $v) {
                return $d->format('Y-m-d H:i:s');
            }
        }

        throw new ValidationException(['paid_at' => ['Use a valid date and time.']]);
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
