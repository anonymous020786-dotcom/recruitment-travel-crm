<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\PassportValidator;
use PHPUnit\Framework\TestCase;

final class PassportValidatorTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'passport_number' => ' a1234567 ', 'issue_date' => '2020-01-01', 'expiry_date' => '2030-01-01',
            'place_of_issue' => 'Mumbai', 'nationality' => 'in', 'is_primary' => '1', 'held_by' => 'agency',
        ], $overrides);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new PassportValidator())->validate($this->valid());

        self::assertSame('A1234567', $out['passport_number']);
        self::assertSame('IN', $out['nationality']);
        self::assertTrue($out['is_primary']);
        self::assertSame('agency', $out['held_by']);
    }

    public function test_rejects_missing_passport_number(): void
    {
        $this->expectException(ValidationException::class);
        (new PassportValidator())->validate($this->valid(['passport_number' => '']));
    }

    public function test_rejects_symbols_in_passport_number(): void
    {
        $this->expectException(ValidationException::class);
        (new PassportValidator())->validate($this->valid(['passport_number' => 'A123-456']));
    }

    public function test_rejects_expiry_before_issue(): void
    {
        try {
            (new PassportValidator())->validate($this->valid(['issue_date' => '2025-01-01', 'expiry_date' => '2020-01-01']));
            self::fail('expected failure');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('expiry_date', $e->errors());
        }
    }

    public function test_defaults_held_by_to_candidate_when_blank(): void
    {
        $out = (new PassportValidator())->validate($this->valid(['held_by' => '']));

        self::assertSame('candidate', $out['held_by']);
    }

    public function test_blank_optional_fields_become_null(): void
    {
        $out = (new PassportValidator())->validate($this->valid([
            'issue_date' => '', 'expiry_date' => '', 'place_of_issue' => '', 'nationality' => '',
        ]));

        self::assertNull($out['issue_date']);
        self::assertNull($out['expiry_date']);
        self::assertNull($out['place_of_issue']);
        self::assertNull($out['nationality']);
    }
}
