<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Matching\MatchEngine;
use PHPUnit\Framework\TestCase;

final class MatchEngineTest extends TestCase
{
    private const TODAY = '2026-09-22';

    private function engine(array $override = []): MatchEngine
    {
        $config = array_replace_recursive(require __DIR__ . '/../../../config/matching.php', $override);

        return new MatchEngine($config);
    }

    private function candidate(array $o = []): array
    {
        return array_merge([
            'experience_years' => 5.0, 'qualification' => 'Diploma in Mechanical Engineering', 'dob' => '1995-03-10',
            'gender' => 'male', 'skills' => [['id' => 1, 'name' => 'Forklift'], ['id' => 2, 'name' => 'Welding']],
            'preferred_countries' => ['AE', 'SA'], 'min_expected_salary' => 1500.0, 'salary_currency' => 'AED',
            'passport_expiry' => '2030-01-01',
        ], $o);
    }

    private function job(array $o = []): array
    {
        return array_merge([
            'country' => 'AE', 'experience_required' => '3+ years', 'qualification' => 'Diploma',
            'age_min' => 21, 'age_max' => 45, 'gender_requirement' => 'male',
            'salary_min' => 1500.0, 'salary_max' => 2000.0, 'currency' => 'AED',
            'requirements' => [
                ['label' => 'Forklift', 'skill_id' => 1, 'is_mandatory' => true, 'weight' => 5],
                ['label' => 'Welding', 'skill_id' => 2, 'is_mandatory' => false, 'weight' => 3],
            ],
        ], $o);
    }

    private function state(\App\Domain\Matching\MatchResult $r, string $key): string
    {
        foreach ($r->criteria as $c) {
            if ($c['key'] === $key) {
                return $c['state'];
            }
        }
        self::fail("criterion {$key} not present");
    }

    public function test_perfect_match_scores_100_and_is_eligible(): void
    {
        $r = $this->engine()->score($this->candidate(), $this->job(), self::TODAY);

        self::assertSame(100.0, $r->score);
        self::assertTrue($r->eligible);
        self::assertSame([], $r->missingMandatory);
        self::assertCount(8, $r->criteria);
        self::assertSame(8, count($r->matched()));
    }

    public function test_missing_mandatory_skill_flags_ineligible_and_lowers_skill_score(): void
    {
        $r = $this->engine()->score($this->candidate(['skills' => [['id' => 2, 'name' => 'Welding']]]), $this->job(), self::TODAY);

        self::assertFalse($r->eligible);
        self::assertSame(['Forklift'], $r->missingMandatory);
        self::assertSame('partial', $this->state($r, 'skills'));
        // skills earn 3/8 of their 40 weight; every other criterion is a full match
        self::assertSame(round((60 + 40 * (3 / 8)) , 1), $r->score);
    }

    public function test_skill_matches_by_name_when_requirement_has_no_catalogue_id(): void
    {
        $job = $this->job(['requirements' => [['label' => 'welding', 'skill_id' => null, 'is_mandatory' => true, 'weight' => 1]]]);

        self::assertSame('matched', $this->state($this->engine()->score($this->candidate(), $job, self::TODAY), 'skills'));
    }

    public function test_ineligibility_can_be_switched_off_in_config(): void
    {
        $r = $this->engine(['ineligible_on_missing_mandatory' => false])
            ->score($this->candidate(['skills' => []]), $this->job(), self::TODAY);

        self::assertTrue($r->eligible);
        self::assertSame(['Forklift'], $r->missingMandatory, 'still reported');
    }

    public function test_job_without_requirements_marks_skills_not_applicable_and_redistributes_weight(): void
    {
        $r = $this->engine()->score($this->candidate(), $this->job(['requirements' => []]), self::TODAY);

        self::assertSame('na', $this->state($r, 'skills'));
        self::assertSame(100.0, $r->score, 'n/a must not count against the candidate');
    }

    public function test_experience_full_partial_and_none(): void
    {
        $e = $this->engine();
        $job = $this->job(['experience_required' => '4 years']);

        self::assertSame('matched', $this->state($e->score($this->candidate(['experience_years' => 4]), $job, self::TODAY), 'experience'));
        self::assertSame('partial', $this->state($e->score($this->candidate(['experience_years' => 3]), $job, self::TODAY), 'experience'));
        self::assertSame('missing', $this->state($e->score($this->candidate(['experience_years' => 1]), $job, self::TODAY), 'experience'), 'below the 50% floor');
        self::assertSame('missing', $this->state($e->score($this->candidate(['experience_years' => null]), $job, self::TODAY), 'experience'));
        self::assertSame('na', $this->state($e->score($this->candidate(), $this->job(['experience_required' => null]), self::TODAY), 'experience'));
    }

    public function test_partial_floor_is_config_driven(): void
    {
        $job = $this->job(['experience_required' => '4 years']);
        $r = $this->engine(['experience_partial_floor' => 0.2])->score($this->candidate(['experience_years' => 1]), $job, self::TODAY);

        self::assertSame('partial', $this->state($r, 'experience'));
    }

    public function test_qualification_matches_either_direction_case_insensitively(): void
    {
        $e = $this->engine();

        self::assertSame('matched', $this->state($e->score($this->candidate(['qualification' => 'DIPLOMA']), $this->job(), self::TODAY), 'qualification'));
        self::assertSame('missing', $this->state($e->score($this->candidate(['qualification' => 'MBA']), $this->job(), self::TODAY), 'qualification'));
        self::assertSame('missing', $this->state($e->score($this->candidate(['qualification' => null]), $this->job(), self::TODAY), 'qualification'));
    }

    public function test_country_preference(): void
    {
        $e = $this->engine();

        self::assertSame('missing', $this->state($e->score($this->candidate(['preferred_countries' => ['QA']]), $this->job(), self::TODAY), 'country'));
        self::assertSame('na', $this->state($e->score($this->candidate(['preferred_countries' => null]), $this->job(), self::TODAY), 'country'));
    }

    public function test_age_range_and_unknown_dob(): void
    {
        $e = $this->engine();

        self::assertSame('missing', $this->state($e->score($this->candidate(['dob' => '1960-01-01']), $this->job(), self::TODAY), 'age'));
        self::assertSame('na', $this->state($e->score($this->candidate(['dob' => null]), $this->job(), self::TODAY), 'age'));
        self::assertSame('na', $this->state($e->score($this->candidate(), $this->job(['age_min' => null, 'age_max' => null]), self::TODAY), 'age'));
    }

    public function test_age_boundary_uses_completed_years(): void
    {
        $job = $this->job(['age_min' => 31, 'age_max' => 31]);
        // Born 1995-09-23: on 2026-09-22 they are still 30, one day short of 31.
        self::assertSame('missing', $this->state($this->engine()->score($this->candidate(['dob' => '1995-09-23']), $job, self::TODAY), 'age'));
        self::assertSame('matched', $this->state($this->engine()->score($this->candidate(['dob' => '1995-09-22']), $job, self::TODAY), 'age'));
    }

    public function test_gender_requirement(): void
    {
        $e = $this->engine();

        self::assertSame('missing', $this->state($e->score($this->candidate(['gender' => 'female']), $this->job(), self::TODAY), 'gender'));
        self::assertSame('na', $this->state($e->score($this->candidate(['gender' => 'undisclosed']), $this->job(), self::TODAY), 'gender'));
        self::assertSame('na', $this->state($e->score($this->candidate(['gender' => 'female']), $this->job(['gender_requirement' => 'any']), self::TODAY), 'gender'));
    }

    public function test_salary_comparison_needs_same_currency(): void
    {
        $e = $this->engine();

        self::assertSame('missing', $this->state($e->score($this->candidate(['min_expected_salary' => 3000.0]), $this->job(), self::TODAY), 'salary'));
        self::assertSame('na', $this->state($e->score($this->candidate(['salary_currency' => 'SAR']), $this->job(), self::TODAY), 'salary'));
        self::assertSame('na', $this->state($e->score($this->candidate(['min_expected_salary' => null]), $this->job(), self::TODAY), 'salary'));
    }

    public function test_passport_validity_window(): void
    {
        $e = $this->engine();

        self::assertSame('missing', $this->state($e->score($this->candidate(['passport_expiry' => null]), $this->job(), self::TODAY), 'passport'));
        self::assertSame('missing', $this->state($e->score($this->candidate(['passport_expiry' => '2026-10-01']), $this->job(), self::TODAY), 'passport'), 'expires within 180 days');
        self::assertSame('missing', $this->state($e->score($this->candidate(['passport_expiry' => '2020-01-01']), $this->job(), self::TODAY), 'passport'));
        self::assertSame('matched', $this->state($this->engine(['passport_min_validity_days' => 5])->score($this->candidate(['passport_expiry' => '2026-10-01']), $this->job(), self::TODAY), 'passport'));
    }

    public function test_zero_weight_switches_a_criterion_off(): void
    {
        $r = $this->engine(['criteria' => ['passport' => ['weight' => 0]]])
            ->score($this->candidate(['passport_expiry' => null]), $this->job(), self::TODAY);

        self::assertCount(7, $r->criteria);
        self::assertSame(100.0, $r->score);
    }

    public function test_breakdown_is_explainable_and_serialisable(): void
    {
        $r = $this->engine()->score($this->candidate(['skills' => []]), $this->job(), self::TODAY);
        $arr = $r->toArray();

        self::assertArrayHasKey('score', $arr);
        self::assertFalse($arr['eligible']);
        foreach ($arr['criteria'] as $c) {
            self::assertNotSame('', $c['detail'], 'every criterion explains itself');
        }
        self::assertNotEmpty($r->missing());
        self::assertJson(json_encode($arr));
    }

    public function test_nothing_applicable_scores_zero_without_dividing_by_zero(): void
    {
        $off = array_fill_keys(['skills', 'experience', 'qualification', 'country', 'age', 'gender', 'salary', 'passport'], ['weight' => 0]);
        $r = $this->engine(['criteria' => $off])->score($this->candidate(), $this->job(), self::TODAY);

        self::assertSame(0.0, $r->score);
        self::assertSame([], $r->criteria);
    }
}
