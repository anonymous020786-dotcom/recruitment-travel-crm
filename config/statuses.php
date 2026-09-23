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

    // 'pending' is in the DB enum for a future "requested but not yet
    // uploaded" flow; DocumentService::upload() always inserts at 'uploaded'.
    'document' => [
        'pending'      => ['uploaded'],
        'uploaded'     => ['under_review', 'verified', 'rejected'],
        'under_review' => ['verified', 'rejected'],
        'verified'     => ['expired'],   // set by cron only
        'rejected'     => [],
        'expired'      => [],
    ],

    // Job posting lifecycle. `closed` and `cancelled` are terminal; a filled
    // job can only be closed. No override permission exists for jobs.
    'job' => [
        'draft'     => ['open', 'cancelled'],
        'open'      => ['paused', 'interview', 'filled', 'closed', 'cancelled'],
        'paused'    => ['open', 'closed', 'cancelled'],
        'interview' => ['open', 'filled', 'closed', 'cancelled'],
        'filled'    => ['closed'],
        'closed'    => [],
        'cancelled' => [],
    ],

    // 'application' => [ ... ]   (Phase 6)
    // 'visa'        => [ ... ]   (Phase 7)
    // 'tour_booking'=> [ ... ]   (Phase 8)
];
