<?php

declare(strict_types=1);

/**
 * Public list prices (USD) behind the storage cost estimator in Admin → Storage. They are estimates for comparing options, not a
 * quote: providers change prices and regions differ — check the provider's pricing page before deciding. Amounts are per GB-month
 * unless stated; requests are per 1,000.
 */
return [
    'as_of' => '2026-01',
    'currency' => 'USD',

    'providers' => [
        's3' => [
            'label' => 'Amazon S3 (Mumbai, ap-south-1)', 'url' => 'https://aws.amazon.com/s3/pricing/',
            'standard' => 0.025, 'infrequent' => 0.0138, 'archive' => 0.005,
            'egress' => 0.1093, 'egress_free_gb' => 100,
            'put_per_1k' => 0.005, 'get_per_1k' => 0.0004, 'free_gb' => 0,
        ],
        'r2' => [
            'label' => 'Cloudflare R2', 'url' => 'https://developers.cloudflare.com/r2/pricing/',
            'standard' => 0.015, 'infrequent' => 0.01, 'archive' => null,
            'egress' => 0.0, 'egress_free_gb' => 0,
            'put_per_1k' => 0.0045, 'get_per_1k' => 0.00036, 'free_gb' => 10,
        ],
    ],
];
