<?php

return [

    'app_name' => env('QISTAS_APP_NAME', 'Qistas'),

    /*
    | Languages shipped with the product. `en` and `ar` are complete; the others fall back to English
    | per key until their translation files are filled in.
    */
    'locales' => ['en', 'ar', 'fr', 'es', 'ur'],
    'rtl_locales' => ['ar', 'ur'],

    'currency_default' => env('QISTAS_DEFAULT_CURRENCY', 'USD'),

    /*
    | Country (ISO 3166-1 alpha-2) => the currency a new workspace starts with. Unknown countries get
    | currency_default. Amounts are handled to 2 decimals; 3-decimal currencies (KWD, BHD, OMR, ...) are a
    | documented limitation of v1.
    */
    'countries' => [
        'SA' => 'SAR', 'AE' => 'AED', 'QA' => 'QAR', 'KW' => 'KWD', 'BH' => 'BHD', 'OM' => 'OMR',
        'EG' => 'EGP', 'JO' => 'JOD', 'LB' => 'LBP', 'IQ' => 'IQD', 'MA' => 'MAD', 'DZ' => 'DZD',
        'TN' => 'TND', 'LY' => 'LYD', 'SD' => 'SDG', 'YE' => 'YER', 'SY' => 'SYP', 'MR' => 'MRU',
        'TR' => 'TRY', 'PK' => 'PKR', 'IN' => 'INR', 'BD' => 'BDT', 'MY' => 'MYR', 'ID' => 'IDR',
        'FR' => 'EUR', 'ES' => 'EUR', 'DE' => 'EUR', 'IT' => 'EUR', 'GB' => 'GBP', 'US' => 'USD',
        'CA' => 'CAD', 'AU' => 'AUD', 'NG' => 'NGN', 'KE' => 'KES', 'ZA' => 'ZAR',
    ],

    'billing' => [
        // fake: instant local checkout for development and tests. stripe: real Stripe Checkout.
        'gateway' => env('BILLING_GATEWAY', 'fake'),
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),
        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
    ],

    'admin' => [
        // Comma-separated IPs allowed to reach /admin. Empty = no IP restriction (2FA is always required).
        'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),
    ],

    'security' => [
        'login_attempts_per_minute' => 5,
        'api_token_days' => 30,
    ],
];
