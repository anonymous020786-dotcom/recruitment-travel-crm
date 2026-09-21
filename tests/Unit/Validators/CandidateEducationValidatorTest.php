<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\CandidateEducationValidator;
use PHPUnit\Framework\TestCase;

final class CandidateEducationValidatorTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'level' => ' Bachelor ', 'institution' => 'City College', 'board_university' => 'State University',
            'field_of_study' => 'Commerce', 'start_year' => '2015', 'end_year' => '2018', 'grade' => 'A',
        ], $overrides);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new CandidateEducationValidator())->validate($this->valid());

        self::assertSame('Bachelor', $out['level']);
        self::assertSame(2015, $out['start_year']);
        self::assertSame(2018, $out['end_year']);
    }

    public function test_rejects_missing_level(): void
    {
        $this->expectException(ValidationException::class);
        (new CandidateEducationValidator())->validate($this->valid(['level' => '']));
    }

    public function test_rejects_end_year_before_start_year(): void
    {
        try {
            (new CandidateEducationValidator())->validate($this->valid(['start_year' => '2018', 'end_year' => '2015']));
            self::fail('expected failure');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('end_year', $e->errors());
        }
    }

    public function test_blank_optional_years_become_null(): void
    {
        $out = (new CandidateEducationValidator())->validate($this->valid(['start_year' => '', 'end_year' => '']));
        self::assertNull($out['start_year']);
        self::assertNull($out['end_year']);
    }
}
