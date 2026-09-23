<?php

declare(strict_types=1);

namespace App\Domain\Matching;

/**
 * Pure, I/O-free candidate ↔ job scorer (same spirit as StatusMachine): it is
 * handed plain arrays and the matching config, and returns a MatchResult.
 * All weights/thresholds come from config('matching'); nothing is hard-coded
 * here except how each criterion is judged.
 *
 * Candidate profile keys: experience_years, qualification, dob (Y-m-d), gender,
 *   skills [{id,name}], preferred_countries (null = none recorded),
 *   min_expected_salary, salary_currency, passport_expiry (latest, Y-m-d|null).
 * Job profile keys: country, experience_required (free text, first number is
 *   the minimum years), qualification, age_min, age_max, gender_requirement,
 *   salary_min, salary_max, currency, requirements [{label,skill_id,is_mandatory,weight}].
 */
final class MatchEngine
{
    /** @param array<string,mixed> $config config('matching') */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $job
     */
    public function score(array $candidate, array $job, ?string $today = null): MatchResult
    {
        $today ??= gmdate('Y-m-d');
        $missingMandatory = [];

        $judged = [
            'skills'        => $this->skills($candidate, $job, $missingMandatory),
            'experience'    => $this->experience($candidate, $job),
            'qualification' => $this->qualification($candidate, $job),
            'country'       => $this->country($candidate, $job),
            'age'           => $this->age($candidate, $job, $today),
            'gender'        => $this->gender($candidate, $job),
            'salary'        => $this->salary($candidate, $job),
            'passport'      => $this->passport($candidate, $today),
        ];

        $criteria = [];
        $earned = 0.0;
        $possible = 0.0;
        foreach ($judged as $key => [$ratio, $detail]) {
            $meta = $this->config['criteria'][$key] ?? ['weight' => 0, 'label' => $key];
            $weight = (int) ($meta['weight'] ?? 0);
            if ($weight <= 0) {
                continue; // switched off in config
            }
            $state = $ratio === null ? 'na' : ($ratio >= 0.999 ? 'matched' : ($ratio > 0.0 ? 'partial' : 'missing'));
            if ($ratio !== null) {
                $earned += $weight * $ratio;
                $possible += $weight;
            }
            $criteria[] = [
                'key' => $key, 'label' => (string) ($meta['label'] ?? $key), 'weight' => $weight,
                'ratio' => $ratio === null ? null : round($ratio, 3), 'state' => $state, 'detail' => $detail,
            ];
        }

        $score = $possible > 0 ? round($earned / $possible * 100, 1) : 0.0;
        $eligible = !((bool) ($this->config['ineligible_on_missing_mandatory'] ?? true) && $missingMandatory !== []);

        return new MatchResult($score, $eligible, $criteria, $missingMandatory);
    }

    /** @return array{0:?float,1:string} */
    private function skills(array $c, array $j, array &$missingMandatory): array
    {
        $reqs = (array) ($j['requirements'] ?? []);
        if ($reqs === []) {
            return [null, 'The job lists no requirements.'];
        }

        $ids = [];
        $names = [];
        foreach ((array) ($c['skills'] ?? []) as $s) {
            $ids[(int) $s['id']] = true;
            $names[mb_strtolower(trim((string) $s['name']))] = true;
        }

        $total = 0;
        $got = 0;
        $have = [];
        $lack = [];
        foreach ($reqs as $r) {
            $w = max(1, (int) ($r['weight'] ?? 1));
            $total += $w;
            $hit = (isset($r['skill_id']) && $r['skill_id'] !== null && isset($ids[(int) $r['skill_id']]))
                || isset($names[mb_strtolower(trim((string) $r['label']))]);
            if ($hit) {
                $got += $w;
                $have[] = (string) $r['label'];
            } else {
                $lack[] = (string) $r['label'] . (!empty($r['is_mandatory']) ? ' (mandatory)' : '');
                if (!empty($r['is_mandatory'])) {
                    $missingMandatory[] = (string) $r['label'];
                }
            }
        }

        $detail = ($have !== [] ? 'Has: ' . implode(', ', $have) . '. ' : '') . ($lack !== [] ? 'Missing: ' . implode(', ', $lack) . '.' : '');

        return [$total > 0 ? $got / $total : null, trim($detail)];
    }

    /** @return array{0:?float,1:string} */
    private function experience(array $c, array $j): array
    {
        $text = trim((string) ($j['experience_required'] ?? ''));
        if ($text === '' || !preg_match('/\d+(?:\.\d+)?/', $text, $m)) {
            return [null, 'The job states no minimum experience.'];
        }
        $need = (float) $m[0];
        if ($need <= 0) {
            return [1.0, 'No experience needed.'];
        }
        $has = $c['experience_years'] ?? null;
        if ($has === null) {
            return [0.0, "Needs {$need}+ years; the candidate's experience is not recorded."];
        }
        $has = (float) $has;
        if ($has >= $need) {
            return [1.0, "{$has} years meets the {$need}+ required."];
        }
        $ratio = $has / $need;
        $floor = (float) ($this->config['experience_partial_floor'] ?? 0.5);

        return $ratio >= $floor
            ? [$ratio, "{$has} of the {$need}+ years required."]
            : [0.0, "{$has} years is below the {$need}+ required."];
    }

    /** @return array{0:?float,1:string} */
    private function qualification(array $c, array $j): array
    {
        $need = mb_strtolower(trim((string) ($j['qualification'] ?? '')));
        if ($need === '') {
            return [null, 'The job states no qualification.'];
        }
        $has = mb_strtolower(trim((string) ($c['qualification'] ?? '')));
        if ($has === '') {
            return [0.0, 'The candidate has no qualification recorded.'];
        }

        return (str_contains($has, $need) || str_contains($need, $has))
            ? [1.0, 'Qualification matches.']
            : [0.0, "\"{$c['qualification']}\" does not match \"{$j['qualification']}\"."];
    }

    /** @return array{0:?float,1:string} */
    private function country(array $c, array $j): array
    {
        $prefs = $c['preferred_countries'] ?? null;
        if ($prefs === null || $prefs === []) {
            return [null, 'No preferred countries recorded.'];
        }

        return in_array(strtoupper((string) $j['country']), array_map('strtoupper', $prefs), true)
            ? [1.0, 'The job country is on the preferred list.']
            : [0.0, 'The job country is not on the preferred list (' . implode(', ', $prefs) . ').'];
    }

    /** @return array{0:?float,1:string} */
    private function age(array $c, array $j, string $today): array
    {
        $min = $j['age_min'] ?? null;
        $max = $j['age_max'] ?? null;
        if ($min === null && $max === null) {
            return [null, 'The job states no age range.'];
        }
        $dob = $c['dob'] ?? null;
        if ($dob === null || strtotime((string) $dob) === false) {
            return [null, 'Date of birth is not recorded.'];
        }
        $age = (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable((string) $dob))->y;
        $ok = ($min === null || $age >= (int) $min) && ($max === null || $age <= (int) $max);

        return [$ok ? 1.0 : 0.0, ($ok ? "Age {$age} is within " : "Age {$age} is outside ") . ($min ?? '—') . '–' . ($max ?? '—') . '.'];
    }

    /** @return array{0:?float,1:string} */
    private function gender(array $c, array $j): array
    {
        $req = (string) ($j['gender_requirement'] ?? 'any');
        if ($req === 'any' || $req === '') {
            return [null, 'The job accepts any gender.'];
        }
        $g = (string) ($c['gender'] ?? '');
        if ($g === '' || $g === 'undisclosed') {
            return [null, 'Gender is not recorded.'];
        }

        return $g === $req ? [1.0, 'Gender requirement met.'] : [0.0, "The job requires {$req}."];
    }

    /** @return array{0:?float,1:string} */
    private function salary(array $c, array $j): array
    {
        $expected = $c['min_expected_salary'] ?? null;
        $offer = $j['salary_max'] ?? $j['salary_min'] ?? null;
        if ($expected === null || $offer === null) {
            return [null, 'Salary expectation or offer is not recorded.'];
        }
        $cc = strtoupper((string) ($c['salary_currency'] ?? ''));
        $jc = strtoupper((string) ($j['currency'] ?? ''));
        if ($cc === '' || $jc === '' || $cc !== $jc) {
            return [null, 'Salary currencies differ or are missing, so they cannot be compared.'];
        }

        return (float) $offer >= (float) $expected
            ? [1.0, "Offer up to {$offer} {$jc} meets the expected {$expected}."]
            : [0.0, "Offer up to {$offer} {$jc} is below the expected {$expected}."];
    }

    /** @return array{0:?float,1:string} */
    private function passport(array $c, string $today): array
    {
        $expiry = $c['passport_expiry'] ?? null;
        if ($expiry === null || strtotime((string) $expiry) === false) {
            return [0.0, 'No passport with an expiry date on file.'];
        }
        $days = (int) ((strtotime((string) $expiry) - strtotime($today)) / 86400);
        $need = (int) ($this->config['passport_min_validity_days'] ?? 180);

        if ($days >= $need) {
            return [1.0, "Passport valid for {$days} more days."];
        }

        return [0.0, $days < 0 ? 'Passport has expired.' : "Passport expires in {$days} days (needs {$need}+)."];
    }
}
