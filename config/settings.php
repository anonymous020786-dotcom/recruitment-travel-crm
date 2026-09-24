<?php

declare(strict_types=1);

/**
 * Settings an administrator may change from Admin → Settings (stored in the `settings` table, JSON values).
 *
 * Only keys listed here are editable or readable through `setting()`. A field left empty falls back to its default:
 * `default` (a literal) or `config` (the value in that config key, i.e. what the environment file says).
 *
 *   type   string | text | email | phone | int
 *   max    longest text (string/text/email/phone) — min/max for `int`
 *   public true → stored with is_public = 1 (shown on the public website)
 *
 * Deliberately NOT here: anything that weakens a control (refund self-approval, password rules, upload limits, 2FA).
 * Those stay in the environment file / config where a change is a deploy, not a click.
 */
return [

    'groups' => [
        'business' => [
            'label' => 'Business profile',
            'help'  => 'Shown on the public website: the footer, the contact page and the search-engine listing. Leave a field empty to hide it.',
        ],
        'finance' => [
            'label' => 'Finance',
            'help'  => 'How the accounts team is nudged about unpaid invoices.',
        ],
    ],

    'fields' => [
        'business.name' => [
            'group' => 'business', 'label' => 'Business name', 'type' => 'string', 'max' => 120, 'public' => true,
            'config' => 'seo.organization_name', 'help' => 'Empty uses the name from the environment file.',
        ],
        'business.phone' => [
            'group' => 'business', 'label' => 'Phone', 'type' => 'phone', 'max' => 30, 'public' => true,
            'help' => 'Shown as a tap-to-call link.',
        ],
        'business.whatsapp' => [
            'group' => 'business', 'label' => 'WhatsApp number', 'type' => 'phone', 'max' => 30, 'public' => true,
            'help' => 'Include the country code, e.g. +91 98765 43210. Shown as a chat link.',
        ],
        'business.email' => [
            'group' => 'business', 'label' => 'Public email', 'type' => 'email', 'max' => 180, 'public' => true,
        ],
        'business.address' => [
            'group' => 'business', 'label' => 'Office address', 'type' => 'text', 'max' => 400, 'public' => true,
        ],
        'business.hours' => [
            'group' => 'business', 'label' => 'Opening hours', 'type' => 'string', 'max' => 160, 'public' => true,
            'help' => 'e.g. Mon–Sat, 10:00–18:00.',
        ],

        'finance.reminder_max_repeats' => [
            'group' => 'finance', 'label' => 'Overdue reminders per invoice', 'type' => 'int', 'min' => 0, 'max' => 20, 'public' => false,
            'config' => 'finance.reminder_max_repeats',
            'help' => 'Reminders repeat weekly for an overdue invoice, up to this many times. 0 sends only the first.',
        ],
    ],
];
