<?php

declare(strict_types=1);

/**
 * Status transition rules for the StatusMachine, per entity.
 *
 *   'entity' => [
 *       'from_status' => ['allowed_to', 'allowed_to', ...],
 *       ...
 *   ]
 *
 * A `from` key mapping to `[]` is terminal. A transition NOT listed here is
 * rejected unless the acting user holds `<module>.override_status` (then it is
 * recorded with is_override = 1 and a mandatory reason).
 *
 * Business can extend/relax these via config; the machine only enforces what is
 * declared. Applications / visa / jobs / tour bookings are filled in their phases.
 */

return [

    'lead' => [
        'new'            => ['contacted', 'follow_up', 'not_interested', 'lost'],
        'contacted'      => ['follow_up', 'interested', 'not_interested', 'lost'],
        'follow_up'      => ['contacted', 'interested', 'counselling', 'not_interested', 'lost'],
        'interested'     => ['counselling', 'follow_up', 'converted', 'not_interested', 'lost'],
        'counselling'    => ['interested', 'converted', 'follow_up', 'not_interested', 'lost'],
        'converted'      => [],   // terminal (won) — set only by the conversion action
        'not_interested' => ['follow_up', 'lost'],
        'lost'           => ['follow_up'],
    ],

    // 'application' => [ ... ]   (Phase 6)
    // 'visa'        => [ ... ]   (Phase 7)
    // 'job'         => [ ... ]   (Phase 5)
    // 'tour_booking'=> [ ... ]   (Phase 8)
];
