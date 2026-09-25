<?php

declare(strict_types=1);

/**
 * Every third-party service the application can talk to, and the credentials each needs — the catalogue behind
 * Admin → Integrations (super admin only).
 *
 * A field is one of:  text · secret (encrypted at rest, never shown again) · select (`options`) · number · url (https only)
 * Optional keys per field:
 *   required  the service counts as "configured" only when every required field has a value
 *   env       an environment variable that supplies the value when nothing is saved in the panel (saved values win)
 *   config    a config path the value is copied into at boot, so existing code (Turnstile, mail, analytics…) keeps
 *             reading config('…') and simply sees what the super admin saved
 *   help      one line of guidance shown under the field
 *
 * Services with no `config` paths are read through App\Integrations\Credentials by their own adapters (payment gateways,
 * storage providers).
 */

$mode = static fn (): array => ['label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'Test / sandbox', 'live' => 'Live'], 'required' => true, 'help' => 'Start in test mode; switch to live only after a successful test payment.'];

return [

    'groups' => [
        'payments_in'   => 'Payment gateways — India',
        'payments_intl' => 'Payment gateways — International',
        'storage'       => 'File storage',
        'mail'          => 'Email',
        'security'      => 'Anti-spam & security',
        'growth'        => 'Analytics & chat',
    ],

    'services' => [

        // ---- Payment gateways: India ----------------------------------------------------------------
        'razorpay' => [
            'label' => 'Razorpay', 'group' => 'payments_in', 'docs' => 'https://razorpay.com/docs/api/',
            'description' => 'UPI, cards, netbanking and wallets. Payment links for invoices, verified webhooks.',
            'fields' => [
                'key_id' => ['label' => 'Key ID', 'type' => 'text', 'required' => true, 'help' => 'Starts with rzp_test_ or rzp_live_.'],
                'key_secret' => ['label' => 'Key secret', 'type' => 'secret', 'required' => true],
                'webhook_secret' => ['label' => 'Webhook secret', 'type' => 'secret', 'required' => true, 'help' => 'Set the same secret on the webhook you create in the Razorpay dashboard.'],
                'mode' => $mode(),
            ],
        ],
        'payu' => [
            'label' => 'PayU India', 'group' => 'payments_in', 'docs' => 'https://docs.payu.in/',
            'description' => 'Cards, UPI, netbanking, EMI. Hosted checkout with hash-verified responses.',
            'fields' => [
                'merchant_key' => ['label' => 'Merchant key', 'type' => 'text', 'required' => true],
                'merchant_salt' => ['label' => 'Merchant salt (v1)', 'type' => 'secret', 'required' => true],
                'mode' => $mode(),
            ],
        ],
        'cashfree' => [
            'label' => 'Cashfree Payments', 'group' => 'payments_in', 'docs' => 'https://docs.cashfree.com/reference/pg-new-apis-endpoint',
            'description' => 'Payment links and orders; UPI, cards, netbanking; signed webhooks.',
            'fields' => [
                'app_id' => ['label' => 'App ID (client id)', 'type' => 'text', 'required' => true],
                'secret_key' => ['label' => 'Secret key (client secret)', 'type' => 'secret', 'required' => true, 'help' => 'Also used to verify webhook signatures.'],
                'mode' => $mode(),
            ],
        ],
        'phonepe' => [
            'label' => 'PhonePe PG', 'group' => 'payments_in', 'docs' => 'https://developer.phonepe.com/',
            'description' => 'UPI-first checkout with X-VERIFY checksummed requests and callbacks.',
            'fields' => [
                'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'required' => true],
                'salt_key' => ['label' => 'Salt key', 'type' => 'secret', 'required' => true],
                'salt_index' => ['label' => 'Salt index', 'type' => 'number', 'required' => true, 'help' => 'Usually 1.'],
                'mode' => $mode(),
            ],
        ],
        'ccavenue' => [
            'label' => 'CCAvenue', 'group' => 'payments_in', 'docs' => 'https://www.ccavenue.com/',
            'description' => 'Cards, netbanking, wallets; AES-encrypted request and response.',
            'fields' => [
                'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'required' => true],
                'access_code' => ['label' => 'Access code', 'type' => 'text', 'required' => true],
                'working_key' => ['label' => 'Working key', 'type' => 'secret', 'required' => true],
                'mode' => $mode(),
            ],
        ],
        'paytm' => [
            'label' => 'Paytm Payment Gateway', 'group' => 'payments_in', 'docs' => 'https://business.paytm.com/docs/',
            'description' => 'Wallet, UPI, cards; checksum-signed transactions.',
            'fields' => [
                'merchant_id' => ['label' => 'Merchant ID (MID)', 'type' => 'text', 'required' => true],
                'merchant_key' => ['label' => 'Merchant key', 'type' => 'secret', 'required' => true],
                'website' => ['label' => 'Website name', 'type' => 'text', 'required' => true, 'help' => 'WEBSTAGING for test, DEFAULT for live (as issued by Paytm).'],
                'mode' => $mode(),
            ],
        ],

        // ---- Payment gateways: international -------------------------------------------------------------
        'stripe' => [
            'label' => 'Stripe', 'group' => 'payments_intl', 'docs' => 'https://docs.stripe.com/api',
            'description' => 'Cards and wallets worldwide. Checkout Sessions, verified webhooks.',
            'fields' => [
                'publishable_key' => ['label' => 'Publishable key', 'type' => 'text', 'required' => true, 'help' => 'pk_test_… or pk_live_…'],
                'secret_key' => ['label' => 'Secret key', 'type' => 'secret', 'required' => true, 'help' => 'sk_… (or a restricted rk_… key).'],
                'webhook_secret' => ['label' => 'Webhook signing secret', 'type' => 'secret', 'required' => true, 'help' => 'whsec_… from the webhook endpoint you add in Stripe.'],
            ],
        ],
        'paypal' => [
            'label' => 'PayPal', 'group' => 'payments_intl', 'docs' => 'https://developer.paypal.com/docs/api/orders/v2/',
            'description' => 'PayPal balance, cards and local methods. Orders API with webhook verification.',
            'fields' => [
                'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true],
                'client_secret' => ['label' => 'Client secret', 'type' => 'secret', 'required' => true],
                'webhook_id' => ['label' => 'Webhook ID', 'type' => 'text', 'required' => true],
                'mode' => $mode(),
            ],
        ],

        // ---- File storage -----------------------------------------------------------------------------
        'storage' => [
            'label' => 'Document storage', 'group' => 'storage', 'docs' => '',
            'description' => 'Where newly uploaded documents are kept, and how they are delivered. Existing documents stay where they are until moved (Admin → Storage).',
            'fields' => [
                'driver' => ['label' => 'Store new uploads on', 'type' => 'select', 'options' => ['private' => 'This server (default)', 's3' => 'Amazon S3', 'r2' => 'Cloudflare R2'], 'help' => 'The chosen provider must be configured below first; otherwise uploads stay on the server.'],
                'delivery' => ['label' => 'Delivering downloads', 'type' => 'select', 'options' => ['redirect' => 'Signed link straight from the bucket (saves server bandwidth)', 'proxy' => 'Through this server'], 'help' => 'Access is checked and logged either way.'],
                'link_seconds' => ['label' => 'Signed link lifetime (seconds)', 'type' => 'number', 'help' => '30–3600, default 120. Short is safer.'],
                'infrequent_after_days' => ['label' => 'Move to cheaper storage after (days)', 'type' => 'number', 'help' => 'Used by “Apply cost-saving rules”. Minimum 30. Default 90.'],
                'archive_after_days' => ['label' => 'Archive tier after (days, Amazon S3 only)', 'type' => 'number', 'help' => 'Glacier Instant Retrieval: still instant to open, a fraction of the price. Default 365.'],
                'backup_retention_days' => ['label' => 'Keep off-site backups for (days)', 'type' => 'number', 'help' => 'Backups copied to the bucket are deleted after this long. Default 30.'],
            ],
        ],
        's3' => [
            'label' => 'Amazon S3', 'group' => 'storage', 'docs' => 'https://docs.aws.amazon.com/AmazonS3/latest/API/',
            'description' => 'Private object storage for documents, backups and exports. Use an IAM user limited to one bucket.',
            'fields' => [
                'access_key' => ['label' => 'Access key ID', 'type' => 'text', 'required' => true],
                'secret_key' => ['label' => 'Secret access key', 'type' => 'secret', 'required' => true],
                'region' => ['label' => 'Region', 'type' => 'text', 'required' => true, 'help' => 'e.g. ap-south-1 (Mumbai).'],
                'bucket' => ['label' => 'Bucket', 'type' => 'text', 'required' => true],
                'prefix' => ['label' => 'Key prefix', 'type' => 'text', 'help' => 'Optional folder inside the bucket, e.g. crm/'],
                'storage_class' => ['label' => 'Storage class for new files', 'type' => 'select', 'options' => ['STANDARD' => 'Standard', 'STANDARD_IA' => 'Standard-IA (cheaper, rarely read)', 'INTELLIGENT_TIERING' => 'Intelligent-Tiering (automatic)', 'GLACIER_IR' => 'Glacier Instant Retrieval (archive)']],
            ],
        ],
        'r2' => [
            'label' => 'Cloudflare R2', 'group' => 'storage', 'docs' => 'https://developers.cloudflare.com/r2/api/s3/api/',
            'description' => 'S3-compatible storage with zero egress fees — usually the cheapest place for documents that are downloaded often.',
            'fields' => [
                'account_id' => ['label' => 'Account ID', 'type' => 'text', 'required' => true],
                'access_key' => ['label' => 'Access key ID', 'type' => 'text', 'required' => true],
                'secret_key' => ['label' => 'Secret access key', 'type' => 'secret', 'required' => true],
                'bucket' => ['label' => 'Bucket', 'type' => 'text', 'required' => true],
                'prefix' => ['label' => 'Key prefix', 'type' => 'text'],
            ],
        ],

        // ---- Email ----------------------------------------------------------------------------------------
        'smtp' => [
            'label' => 'Email (SMTP)', 'group' => 'mail', 'docs' => '',
            'description' => 'The mail server the CRM sends through (reminders, password resets, receipts).',
            'fields' => [
                'host' => ['label' => 'SMTP host', 'type' => 'text', 'required' => true, 'config' => 'mail.smtp.host', 'env' => 'MAIL_HOST'],
                'port' => ['label' => 'Port', 'type' => 'number', 'required' => true, 'config' => 'mail.smtp.port', 'env' => 'MAIL_PORT'],
                'encryption' => ['label' => 'Encryption', 'type' => 'select', 'options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'], 'config' => 'mail.smtp.encryption', 'env' => 'MAIL_ENCRYPTION'],
                'username' => ['label' => 'Username', 'type' => 'text', 'required' => true, 'config' => 'mail.smtp.username', 'env' => 'MAIL_USERNAME'],
                'password' => ['label' => 'Password', 'type' => 'secret', 'required' => true, 'config' => 'mail.smtp.password', 'env' => 'MAIL_PASSWORD'],
                'from_address' => ['label' => 'From address', 'type' => 'text', 'config' => 'mail.from.address', 'env' => 'MAIL_FROM_ADDRESS'],
                'from_name' => ['label' => 'From name', 'type' => 'text', 'config' => 'mail.from.name', 'env' => 'MAIL_FROM_NAME'],
            ],
        ],

        // ---- Anti-spam & security ---------------------------------------------------------------------------
        'turnstile' => [
            'label' => 'Cloudflare Turnstile', 'group' => 'security', 'docs' => 'https://developers.cloudflare.com/turnstile/',
            'description' => 'Bot protection on the public contact, apply and enquiry forms.',
            'fields' => [
                'site_key' => ['label' => 'Site key', 'type' => 'text', 'required' => true, 'config' => 'integrations.turnstile.site_key', 'env' => 'INTEGRATIONS_TURNSTILE_SITE_KEY'],
                'secret_key' => ['label' => 'Secret key', 'type' => 'secret', 'required' => true, 'config' => 'integrations.turnstile.secret_key', 'env' => 'INTEGRATIONS_TURNSTILE_SECRET_KEY'],
            ],
        ],

        // ---- Analytics & chat ----------------------------------------------------------------------------------
        'ga4' => [
            'label' => 'Google Analytics 4', 'group' => 'growth', 'docs' => 'https://support.google.com/analytics/',
            'description' => 'Public-site analytics (only after the visitor accepts the cookie notice).',
            'fields' => [
                'measurement_id' => ['label' => 'Measurement ID', 'type' => 'text', 'required' => true, 'config' => 'integrations.analytics.ga4_id', 'env' => 'INTEGRATIONS_GA4_ID', 'help' => 'G-XXXXXXXXXX'],
            ],
        ],
        'tawk' => [
            'label' => 'Tawk.to live chat', 'group' => 'growth', 'docs' => 'https://help.tawk.to/',
            'description' => 'Live-chat widget on the public site.',
            'fields' => [
                'property_id' => ['label' => 'Property ID', 'type' => 'text', 'required' => true, 'config' => 'integrations.tawk.property_id', 'env' => 'INTEGRATIONS_TAWK_PROPERTY_ID'],
                'widget_id' => ['label' => 'Widget ID', 'type' => 'text', 'config' => 'integrations.tawk.widget_id', 'env' => 'INTEGRATIONS_TAWK_WIDGET_ID', 'help' => 'Leave empty for "default".'],
            ],
        ],
        'whatsapp' => [
            'label' => 'WhatsApp click-to-chat', 'group' => 'growth', 'docs' => '',
            'description' => 'The floating "chat on WhatsApp" button on the public site.',
            'fields' => [
                'number' => ['label' => 'Number', 'type' => 'text', 'required' => true, 'config' => 'integrations.whatsapp.number', 'env' => 'INTEGRATIONS_WHATSAPP_NUMBER', 'help' => 'International format, digits only, e.g. 919812345678.'],
            ],
        ],
    ],
];
