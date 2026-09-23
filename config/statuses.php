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

    // Application pipeline (docs/00-ARCHITECTURE.md §9.3). KEY ORDER IS
    // MEANINGFUL: it is the pipeline order used to derive the candidate's
    // denormalised `stage` snapshot (most advanced live application wins).
    // Additions to the documented table: `rescheduled`/`no_show` (which the
    // doc lists as targets but never defines) and `interview_completed →
    // interview_scheduled` for a further interview round.
    // `rejected` is terminal, but an actor holding applications.override_status
    // may reopen it — audited with a mandatory reason.
    'application' => [
        'applied'             => ['shortlisted', 'documents_submitted', 'rejected', 'cancelled'],
        'documents_submitted' => ['shortlisted', 'rejected', 'cancelled'],
        'shortlisted'         => ['interview_scheduled', 'rejected', 'cancelled'],
        'interview_scheduled' => ['interview_completed', 'rescheduled', 'no_show', 'cancelled'],
        'rescheduled'         => ['interview_scheduled', 'cancelled'],
        'no_show'             => ['interview_scheduled', 'rejected', 'cancelled'],
        'interview_completed' => ['selected', 'rejected', 'interview_scheduled', 'cancelled'],
        'selected'            => ['offer_received', 'cancelled'],
        'offer_received'      => ['offer_accepted', 'rejected', 'cancelled'],
        'offer_accepted'      => ['medical_pending', 'cancelled'],
        'medical_pending'     => ['medical_completed', 'cancelled'],
        'medical_completed'   => ['visa_processing', 'cancelled'],
        'visa_processing'     => ['visa_approved', 'rejected', 'cancelled'],
        'visa_approved'       => ['ticket_pending', 'cancelled'],
        'ticket_pending'      => ['ticket_booked', 'cancelled'],
        'ticket_booked'       => ['departed', 'ticket_pending', 'cancelled'],   // back to ticket_pending when the only ticket is cancelled
        'departed'            => ['placed'],
        'placed'              => [],   // terminal (won)
        'rejected'            => [],   // terminal (override to reopen)
        'cancelled'           => [],   // terminal
    ],

    // Medical examination. `retest` is terminal for the record: a retest is a NEW record.
    'medical' => [
        'pending'   => ['scheduled', 'completed'],
        'scheduled' => ['completed', 'fit', 'unfit', 'retest'],
        'completed' => ['fit', 'unfit', 'retest'],
        'fit'       => [],
        'unfit'     => [],
        'retest'    => [],
    ],

    // Visa application. rejected / expired / cancelled are closed; an actor holding
    // visa.override_status may reopen one (audited, reason mandatory).
    'visa' => [
        'not_started'       => ['documents_pending', 'submitted', 'cancelled'],
        'documents_pending' => ['submitted', 'cancelled'],
        'submitted'         => ['under_processing', 'approved', 'rejected', 'cancelled'],
        'under_processing'  => ['approved', 'rejected', 'cancelled'],
        'approved'          => ['expired', 'cancelled'],
        'rejected'          => [],
        'expired'           => [],
        'cancelled'         => [],
    ],

    // Flight booking. `flown` is set by recording the departure; `cancelled` and
    // `flown` are terminal. `changed` = itinerary altered, waiting to be re-ticketed.
    'flight' => [
        'planned'   => ['booked', 'issued', 'cancelled'],
        'booked'    => ['issued', 'changed', 'flown', 'cancelled'],
        'issued'    => ['changed', 'flown', 'cancelled'],
        'changed'   => ['booked', 'issued', 'cancelled'],
        'flown'     => [],
        'cancelled' => [],
    ],

    // Placement after arrival. Every non-active state is final.
    'placement' => [
        'active'     => ['completed', 'terminated', 'absconded'],
        'completed'  => [],
        'terminated' => [],
        'absconded'  => [],
    ],

    // Tour package catalogue entry. Only an `active` package can be public; leaving
    // `active` withdraws it from the public site. `archived` is read-only until reactivated.
    'tour_package' => [
        'draft'    => ['active', 'archived'],
        'active'   => ['draft', 'archived'],
        'archived' => ['active'],
    ],

    // Tour booking (a customer buying a package). Independent of the recruitment
    // pipeline. `travelling` → `completed` is the only way out of a trip in progress;
    // `completed` and `cancelled` are final (a cancelled inquiry is replaced by a new booking).
    'tour_booking' => [
        'inquiry'    => ['quoted', 'confirmed', 'cancelled'],
        'quoted'     => ['confirmed', 'cancelled'],
        'confirmed'  => ['travelling', 'cancelled'],
        'travelling' => ['completed'],
        'completed'  => [],
        'cancelled'  => [],
    ],
];
