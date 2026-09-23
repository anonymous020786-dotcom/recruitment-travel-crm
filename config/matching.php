<?php

declare(strict_types=1);

/**
 * Candidate ↔ job match engine (App\Domain\Matching\MatchEngine).
 *
 * Every criterion has a weight; a criterion that cannot be judged for a given
 * pair (e.g. the job states no age limit, or the candidate's date of birth is
 * unknown) is marked "n/a" and dropped from BOTH the numerator and the
 * denominator, so missing data never silently inflates or deflates a score.
 * Score = Σ(weight × earned ratio) / Σ(weight of applicable criteria) × 100.
 *
 * Set a weight to 0 to switch a criterion off entirely.
 */
return [
    'criteria' => [
        'skills'        => ['weight' => 40, 'label' => 'Required skills'],
        'experience'    => ['weight' => 20, 'label' => 'Experience'],
        'qualification' => ['weight' => 10, 'label' => 'Qualification'],
        'country'       => ['weight' => 10, 'label' => 'Preferred country'],
        'age'           => ['weight' => 5,  'label' => 'Age range'],
        'gender'        => ['weight' => 5,  'label' => 'Gender'],
        'salary'        => ['weight' => 5,  'label' => 'Salary expectation'],
        'passport'      => ['weight' => 5,  'label' => 'Passport validity'],
    ],

    // Experience: a candidate with at least this share of the required years
    // earns proportional partial credit; below it, nothing.
    'experience_partial_floor' => 0.5,

    // A passport counts as valid if it stays valid at least this many days.
    'passport_min_validity_days' => 180,

    // A candidate missing any MANDATORY requirement is flagged ineligible
    // (their score is still shown, and ranking lists eligible candidates first).
    'ineligible_on_missing_mandatory' => true,

    // Lists show at most this many candidates / jobs, and only at or above the
    // minimum score.
    'pool_limit'      => 300,
    'result_limit'    => 25,
    'min_score_shown' => 0,
];
