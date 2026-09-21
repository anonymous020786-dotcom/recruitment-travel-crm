<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\CandidateExperienceValidator;
use PHPUnit\Framework\TestCase;

final class CandidateExperienceValidatorTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'employer_name' => ' Acme Travel ', 'job_title' => 'Consultant', 'country' => 'ae',
            'start_date' => '2020-01-01', 'end_date' => '2022-06-30', 'is_current' => '0',
            'responsibilities' => 'Handled client onboarding.',
        ], $overrides);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new CandidateExperienceValidator())->validate($this->valid());

        self::assertSame('Acme Travel', $out['employer_name']);
        self::assertSame('AE', $out['country']);
        self::assertFalse($out['is_current']);
    }

    public function test_rejects_missing_employer_name(): void
    {
        $this->expectException(ValidationException::class);
        (new CandidateExperienceValidator())->validate($this->valid(['employer_name' => '']));
    }

    public function test_rejects_end_date_before_start_date(): void
    {
        try {
            (new CandidateExperienceValidator())->validate($this->valid(['start_date' => '2022-01-01', 'end_date' => '2020-01-01']));
            self::fail('expected failure');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('end_date', $e->errors());
        }
    }

    public function test_is_current_clears_end_date(): void
    {
        $out = (new CandidateExperienceValidator())->validate($this->valid(['is_current' => '1', 'end_date' => '2022-06-30']));

        self::assertTrue($out['is_current']);
        self::assertNull($out['end_date']);
    }
}
