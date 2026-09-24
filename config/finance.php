<?php

declare(strict_types=1);

use App\Support\Env;

return [
    // Separation of duties: the person who requested a refund cannot approve it. A one-person
    // office can switch this on (FINANCE_ALLOW_SELF_APPROVAL=1); every approval is audited either way.
    'allow_self_approval' => Env::bool('FINANCE_ALLOW_SELF_APPROVAL', false),

    // Overdue-invoice reminders repeat weekly, up to this many times per invoice.
    'reminder_max_repeats' => Env::int('FINANCE_REMINDER_MAX_REPEATS', 8),
];
