<?php

declare(strict_types=1);

namespace App\Validators;

final class EmployerValidator
{
    private const RULES = [
        'company_name'   => 'required|string|max:180',
        'country'        => 'required|string|size:2|alpha',
        'city'           => 'nullable|string|max:90',
        'address'        => 'nullable|string|max:255',
        'industry'       => 'nullable|string|max:120',
        'website'        => 'nullable|url|max:180',
        'license_number' => 'nullable|string|max:80',
        'license_expiry' => 'nullable|date',
        'status'         => 'nullable|in:prospect,active,suspended,blacklisted,inactive',
        'account_owner'  => 'nullable|integer',
        'notes'          => 'nullable|string|max:5000',
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

        $clean = Validator::make($data, self::RULES)->validated();
        $clean['country'] = strtoupper((string) $clean['country']);

        foreach (['city', 'address', 'industry', 'website', 'license_number', 'license_expiry', 'notes'] as $k) {
            if (array_key_exists($k, $clean)) {
                $clean[$k] = ($clean[$k] ?? '') !== '' ? $clean[$k] : null;
            }
        }
        if (array_key_exists('status', $clean)) {
            $clean['status'] = ($clean['status'] ?? '') !== '' ? $clean['status'] : 'active';
        }
        if (array_key_exists('account_owner', $clean)) {
            $clean['account_owner'] = ($clean['account_owner'] ?? '') !== '' ? (int) $clean['account_owner'] : null;
        }

        return $clean;
    }
}
