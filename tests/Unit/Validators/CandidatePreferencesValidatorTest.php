<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\CandidatePreferencesValidator;
use PHPUnit\Framework\TestCase;

final class CandidatePreferencesValidatorTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'preferred_countries' => 'ae, sa, QA', 'preferred_job_titles' => 'Driver, Cook',
            'min_expected_salary' => '2500', 'salary_currency' => 'aed',
            'willing_to_relocate' => '1', 'available_from' => '2027-01-01',
            'passport_ready' => '0', 'notes' => 'Prefers day shift.',
        ], $overrides);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new CandidatePreferencesValidator())->validate($this->valid());

        self::assertSame(['AE', 'SA', 'QA'], $out['preferred_countries']);
        self::assertSame(['Driver', 'Cook'], $out['preferred_job_titles']);
        self::assertSame('AED', $out['salary_currency']);
        self::assertTrue($out['willing_to_relocate']);
        self::assertFalse($out['passport_ready']);
    }

    public function test_rejects_malformed_country_code(): void
    {
        $this->expectException(ValidationException::class);
        (new CandidatePreferencesValidator())->validate($this->valid(['preferred_countries' => 'United Arab Emirates']));
    }

    public function test_blank_lists_become_empty_arrays(): void
    {
        $out = (new CandidatePreferencesValidator())->validate($this->valid(['preferred_countries' => '', 'preferred_job_titles' => '']));

        self::assertSame([], $out['preferred_countries']);
        self::assertSame([], $out['preferred_job_titles']);
    }

    public function test_unchecked_checkboxes_default_via_hidden_zero_field(): void
    {
        $out = (new CandidatePreferencesValidator())->validate($this->valid(['willing_to_relocate' => '0', 'passport_ready' => '0']));

        self::assertFalse($out['willing_to_relocate']);
        self::assertFalse($out['passport_ready']);
    }

    public function test_blank_optional_scalars_become_null(): void
    {
        $out = (new CandidatePreferencesValidator())->validate($this->valid([
            'min_expected_salary' => '', 'salary_currency' => '', 'available_from' => '', 'notes' => '',
        ]));

        self::assertNull($out['min_expected_salary']);
        self::assertNull($out['salary_currency']);
        self::assertNull($out['available_from']);
        self::assertNull($out['notes']);
    }
}
