<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\LeadValidator;
use PHPUnit\Framework\TestCase;

final class LeadValidatorTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'name' => '  Asha Rao ', 'phone' => '98123 45678', 'priority' => 'medium',
        ], $overrides);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new LeadValidator())->validate($this->valid([
            'email' => ' ASHA@Example.COM ', 'interested_country' => 'ae', 'salary_currency' => 'aed',
        ]));

        self::assertSame('Asha Rao', $out['name']);
        self::assertSame('98123 45678', $out['phone']);
        self::assertSame('asha@example.com', $out['email']);
        self::assertSame('AE', $out['interested_country']);
        self::assertSame('AED', $out['salary_currency']);
    }

    public function test_rejects_missing_name(): void
    {
        $this->expectException(ValidationException::class);
        (new LeadValidator())->validate(['phone' => '9812345678', 'priority' => 'low']);
    }

    public function test_rejects_bad_phone(): void
    {
        try {
            (new LeadValidator())->validate($this->valid(['phone' => 'call-me']));
            self::fail('expected failure');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('phone', $e->errors());
        }
    }

    public function test_rejects_bad_priority_and_country(): void
    {
        try {
            (new LeadValidator())->validate($this->valid(['priority' => 'whenever', 'interested_country' => 'UAE']));
            self::fail('expected failure');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('priority', $e->errors());
            self::assertArrayHasKey('interested_country', $e->errors());
        }
    }

    public function test_future_dob_rejected(): void
    {
        try {
            (new LeadValidator())->validate($this->valid(['date_of_birth' => date('Y-m-d', strtotime('+1 year'))]));
            self::fail('expected failure');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('date_of_birth', $e->errors());
        }
    }

    public function test_blank_optional_fields_become_null(): void
    {
        $out = (new LeadValidator())->validate($this->valid(['city' => '', 'email' => '', 'source_id' => '']));
        self::assertNull($out['city']);
        self::assertNull($out['email']);
        self::assertNull($out['source_id']);
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $out = (new LeadValidator())->validate($this->valid([
            'branch_id' => 999, 'status_id' => 5, 'record_version' => 99, 'converted_candidate_id' => 1,
        ]));
        foreach (['branch_id', 'status_id', 'record_version', 'converted_candidate_id'] as $k) {
            self::assertArrayNotHasKey($k, $out);
        }
    }
}
