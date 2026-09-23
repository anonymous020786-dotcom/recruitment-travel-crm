<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\JobRequirementValidator;
use App\Validators\JobValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobValidatorTest extends TestCase
{
    private function valid(array $o = []): array
    {
        return array_merge([
            'title' => ' Senior Driver ', 'country' => 'ae', 'city' => '', 'vacancies' => '5',
            'salary_min' => '1500', 'salary_max' => '2000', 'currency' => 'aed',
            'experience_required' => '', 'qualification' => '', 'age_min' => '21', 'age_max' => '45',
            'gender_requirement' => '', 'accommodation' => 'provided', 'food' => '', 'transport' => '',
            'working_hours' => '', 'overtime' => '', 'contract_duration_months' => '24',
            'interview_type' => '', 'deadline' => '', 'description' => "Line one\n\nLine <two>",
        ], $o);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new JobValidator())->validate($this->valid());

        self::assertSame('Senior Driver', $out['title']);
        self::assertSame('AE', $out['country']);
        self::assertSame(5, $out['vacancies']);
        self::assertSame(1500.0, $out['salary_min']);
        self::assertSame('AED', $out['currency']);
        self::assertSame('any', $out['gender_requirement']);
        self::assertSame('none', $out['food']);
        self::assertNull($out['deadline']);
        self::assertNull($out['interview_type']);
        self::assertSame('<p>Line one</p><p>Line &lt;two&gt;</p>', $out['description_html']);
        self::assertArrayNotHasKey('description', $out);
    }

    #[DataProvider("badInputs")]
    public function test_rejects_invalid_input(array $override): void
    {
        $this->expectException(ValidationException::class);
        (new JobValidator())->validate($this->valid($override));
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function badInputs(): array
    {
        return [
            'no title'            => [['title' => '']],
            'zero vacancies'      => [['vacancies' => '0']],
            'bad country'         => [['country' => 'UAE']],
            'salary max < min'    => [['salary_min' => '2000', 'salary_max' => '1000']],
            'salary without cur.' => [['currency' => '']],
            'age max < min'       => [['age_min' => '40', 'age_max' => '30']],
            'bad accommodation'   => [['accommodation' => 'luxury']],
            'bad interview type'  => [['interview_type' => 'carrier-pigeon']],
            'bad deadline'        => [['deadline' => 'someday']],
        ];
    }

    public function test_requirement_defaults_and_validation(): void
    {
        $out = (new JobRequirementValidator())->validate(['label' => ' Forklift licence ', 'is_mandatory' => '0', 'weight' => '']);

        self::assertSame('Forklift licence', $out['label']);
        self::assertFalse($out['is_mandatory']);
        self::assertSame(1, $out['weight']);

        $this->expectException(ValidationException::class);
        (new JobRequirementValidator())->validate(['label' => 'x', 'weight' => '11']);
    }
}
