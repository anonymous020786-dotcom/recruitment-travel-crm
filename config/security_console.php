<?php

declare(strict_types=1);

/**
 * What Admin → Security → Rate limits shows and allows. The limits themselves live in config/rate_limits.php (the defaults);
 * a value saved in the panel overrides it (see App\Security\SecurityPolicy).
 *
 * `strict` buckets guard passwords and codes: they may be tightened freely but loosened by at most double their default, so a
 * slip of the keyboard cannot open the door to brute forcing.
 */
return [
    'bounds' => [
        'limit'  => ['min' => 1,  'max' => 100000],
        'window' => ['min' => 10, 'max' => 86400],
    ],
    'strict_factor' => 2,

    'buckets' => [
        'login'            => ['label' => 'Sign-in attempts',              'help' => 'Password tries per address + email address.',                       'strict' => true],
        'two_factor'       => ['label' => 'Two-factor code tries',         'help' => 'Codes tried on the second-step screen, per address.',                'strict' => true],
        'password_confirm' => ['label' => 'Password confirmation',         'help' => 'Re-entering the password before a sensitive action.',                'strict' => true],
        'password_reset'   => ['label' => 'Password-reset requests',       'help' => '“Forgot password” emails requested, per address.',                    'strict' => true],
        'search'           => ['label' => 'Global search',                 'help' => 'Searches per signed-in user.'],
        'dashboard'        => ['label' => 'Dashboards and reports',        'help' => 'Heavy read pages per signed-in user.'],
        'write'            => ['label' => 'Saving changes',                'help' => 'Create / edit / delete actions per signed-in user.'],
        'payments.write'   => ['label' => 'Recording payments',            'help' => 'Payment, refund and instalment actions per signed-in user.'],
        'upload'           => ['label' => 'File uploads',                  'help' => 'Documents and images uploaded per signed-in user.'],
        'import'           => ['label' => 'Imports',                       'help' => 'Bulk imports per signed-in user.'],
        'export'           => ['label' => 'Exports',                       'help' => 'Data exports per signed-in user.'],
        'public_form'      => ['label' => 'Public website forms',          'help' => 'Enquiry / application forms submitted from one address.'],
        'pay_public'       => ['label' => 'Public payment pages',          'help' => 'Customers opening or starting a payment link, per address.'],
        'webhook'          => ['label' => 'Payment webhooks',              'help' => 'Provider callbacks, per address. Keep it generous: gateways retry in bursts.'],
    ],

    // Automatic IP block after repeated failed sign-ins (0 = off).
    'autoblock' => [
        'threshold' => ['min' => 5,  'max' => 1000, 'default' => 0],
        'minutes'   => ['min' => 5,  'max' => 10080, 'default' => 60],
        'window'    => 900,     // failures are counted over the last 15 minutes
    ],
];
