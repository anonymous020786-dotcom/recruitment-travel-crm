<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

/** Validates the refund request form and the reject reason. */
final class RefundValidator
{
    /**
     * @param array<string,mixed> $data
     * @return array{amount:string,method:string,reason:string,invoice:?string}
     */
    public function request(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }
        $clean = Validator::make($data, [
            'amount'  => 'required|numeric|min:0.01|max:9999999999999',
            'method'  => 'required|in:cash,bank_transfer,upi,card,cheque,adjustment',
            'reason'  => 'required|string|min:3|max:255',
            'invoice' => 'nullable|string|max:26',
        ], ['amount.min' => 'The amount must be above zero.', 'reason.required' => 'Please say why this refund is being made.'])->validated();

        return [
            'amount'  => number_format((float) $clean['amount'], 2, '.', ''),
            'method'  => (string) $clean['method'],
            'reason'  => (string) $clean['reason'],
            'invoice' => ($clean['invoice'] ?? '') !== '' ? (string) $clean['invoice'] : null,
        ];
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
}
