<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\CandidateSkillValidator;
use PHPUnit\Framework\TestCase;

final class CandidateSkillValidatorTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'skill_name' => ' MS Excel ', 'category' => 'Office', 'proficiency' => 'advanced', 'years' => '3',
        ], $overrides);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new CandidateSkillValidator())->validate($this->valid());

        self::assertSame('MS Excel', $out['skill_name']);
        self::assertSame('advanced', $out['proficiency']);
        self::assertSame(3.0, $out['years']);
    }

    public function test_rejects_missing_skill_name(): void
    {
        $this->expectException(ValidationException::class);
        (new CandidateSkillValidator())->validate($this->valid(['skill_name' => '']));
    }

    public function test_rejects_invalid_proficiency(): void
    {
        $this->expectException(ValidationException::class);
        (new CandidateSkillValidator())->validate($this->valid(['proficiency' => 'guru']));
    }

    public function test_defaults_proficiency_to_intermediate_when_blank(): void
    {
        $out = (new CandidateSkillValidator())->validate($this->valid(['proficiency' => '']));

        self::assertSame('intermediate', $out['proficiency']);
    }

    public function test_blank_years_becomes_null(): void
    {
        $out = (new CandidateSkillValidator())->validate($this->valid(['years' => '']));

        self::assertNull($out['years']);
    }
}
