<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\EmployerContactValidator;
use App\Validators\EmployerValidator;
use PHPUnit\Framework\TestCase;

final class EmployerValidatorTest extends TestCase
{
    private function valid(array $o = []): array
    {
        return array_merge([
            'company_name' => ' Al Noor Trading ', 'country' => 'ae', 'city' => 'Dubai', 'address' => '',
            'industry' => 'Construction', 'website' => 'https://alnoor.example', 'license_number' => 'L-1',
            'license_expiry' => '2030-01-01', 'status' => 'active', 'account_owner' => '', 'notes' => '',
        ], $o);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new EmployerValidator())->validate($this->valid());

        self::assertSame('Al Noor Trading', $out['company_name']);
        self::assertSame('AE', $out['country']);
        self::assertNull($out['address']);
        self::assertNull($out['account_owner']);
    }

    public function test_rejects_missing_company_name(): void
    {
        $this->expectException(ValidationException::class);
        (new EmployerValidator())->validate($this->valid(['company_name' => '']));
    }

    public function test_rejects_bad_country_and_status_and_website(): void
    {
        foreach ([['country' => 'UAE'], ['status' => 'bogus'], ['website' => 'not a url']] as $bad) {
            try {
                (new EmployerValidator())->validate($this->valid($bad));
                self::fail('expected failure for ' . json_encode($bad));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_blank_status_defaults_to_active(): void
    {
        self::assertSame('active', (new EmployerValidator())->validate($this->valid(['status' => '']))['status']);
    }

    public function test_contact_normalises_and_validates(): void
    {
        $out = (new EmployerContactValidator())->validate([
            'name' => ' Sara ', 'designation' => '', 'email' => 'SARA@X.COM', 'phone' => '+971 50 123 4567', 'is_primary' => '1',
        ]);

        self::assertSame('Sara', $out['name']);
        self::assertNull($out['designation']);
        self::assertSame('sara@x.com', $out['email']);
        self::assertTrue($out['is_primary']);
    }

    public function test_contact_rejects_bad_phone_and_missing_name(): void
    {
        foreach ([['name' => ''], ['phone' => 'abc']] as $bad) {
            try {
                (new EmployerContactValidator())->validate($bad + ['name' => 'X', 'phone' => '']);
                self::fail('expected failure');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
