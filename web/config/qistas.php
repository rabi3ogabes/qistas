<?php

return [

    'app_name' => env('QISTAS_APP_NAME', 'Qistas'),

    // Where the admin's "Get the app" button goes: the page with the latest Android build.
    'app_download_url' => env('APP_DOWNLOAD_URL', 'https://github.com/rabi3ogabes/qistas/releases/latest'),

    /*
    | Languages shipped with the product. `en` and `ar` are complete; the others fall back to English
    | per key until their translation files are filled in.
    */
    'locales' => ['en', 'ar', 'fr', 'es', 'ur'],
    'rtl_locales' => ['ar', 'ur'],

    /*
    | True when the app runs as a throw-away demo (no database and key configured: see docker/entrypoint.sh).
    | Shows a banner on every page and the demo sign-in on the sign-in page. Never true on a real site.
    */
    'demo' => filter_var(env('QISTAS_DEMO', false), FILTER_VALIDATE_BOOL),

    'currency_default' => env('QISTAS_DEFAULT_CURRENCY', 'USD'),

    // How a customer can pay. Stored on every transaction.
    'payment_methods' => ['cash', 'bank_transfer', 'card', 'cheque', 'other'],

    // Shown to suspended users. Null hides it.
    'support_email' => env('QISTAS_SUPPORT_EMAIL'),

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

    /*
    | The time zone a new workspace starts with, by country (IANA names). The owner can change it later; a workspace
    | with none stored uses this too. Every country above must be here (a test checks), and anything else is UTC.
    */
    'timezones' => [
        'SA' => 'Asia/Riyadh', 'AE' => 'Asia/Dubai', 'QA' => 'Asia/Qatar', 'KW' => 'Asia/Kuwait', 'BH' => 'Asia/Bahrain',
        'OM' => 'Asia/Muscat', 'EG' => 'Africa/Cairo', 'JO' => 'Asia/Amman', 'LB' => 'Asia/Beirut', 'IQ' => 'Asia/Baghdad',
        'MA' => 'Africa/Casablanca', 'DZ' => 'Africa/Algiers', 'TN' => 'Africa/Tunis', 'LY' => 'Africa/Tripoli',
        'SD' => 'Africa/Khartoum', 'YE' => 'Asia/Aden', 'SY' => 'Asia/Damascus', 'MR' => 'Africa/Nouakchott',
        'TR' => 'Europe/Istanbul', 'PK' => 'Asia/Karachi', 'IN' => 'Asia/Kolkata', 'BD' => 'Asia/Dhaka',
        'MY' => 'Asia/Kuala_Lumpur', 'ID' => 'Asia/Jakarta', 'FR' => 'Europe/Paris', 'ES' => 'Europe/Madrid',
        'DE' => 'Europe/Berlin', 'IT' => 'Europe/Rome', 'GB' => 'Europe/London', 'US' => 'America/New_York',
        'CA' => 'America/Toronto', 'AU' => 'Australia/Sydney', 'NG' => 'Africa/Lagos', 'KE' => 'Africa/Nairobi',
        'ZA' => 'Africa/Johannesburg',
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

    /*
    | Who may tell the app about the original request (https, client address) through X-Forwarded-* headers.
    | Null trusts nobody. "*" trusts any proxy: right behind Vercel or a load balancer that is the only way in,
    | wrong when the app is reachable directly. Or a comma-separated list of proxy addresses.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES') === null || env('TRUSTED_PROXIES') === ''
        ? null
        : (env('TRUSTED_PROXIES') === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES')))))),

    'security' => [
        'login_attempts_per_minute' => 5,
        'api_token_days' => 30,
    ],

    /*
    | "Try the demo" buttons on the sign-in page and in the app. Each press makes a throw-away account with its own
    | workspace and sample data (an "admin" on the Pro plan with every feature, or a "user" on the Free plan with its
    | limits), signed in at once. It is nobody's real account: it expires, is deleted, and can never be platform
    | staff. Off unless QISTAS_DEMO_LOGIN is true (or the whole site is a demo, QISTAS_DEMO).
    */
    'demo_login' => [
        'enabled' => filter_var(env('QISTAS_DEMO_LOGIN', false), FILTER_VALIDATE_BOOL),
        'hours' => (int) env('QISTAS_DEMO_HOURS', 12),
        'per_hour' => (int) env('QISTAS_DEMO_PER_HOUR', 8), // per address
        'max_accounts' => (int) env('QISTAS_DEMO_MAX', 300),
    ],

    /*
    | Scheduled work on a host with no scheduler and no queue worker. Something outside (a GitHub workflow, Vercel Cron,
    | any pinger) calls /internal/cron every few minutes with `Authorization: Bearer <CRON_SECRET>`. With no secret the
    | endpoint is closed (503). max_seconds caps how long one call works through the queue (a job already running is
    | never cut short, so keep it well under the host's request limit).
    */
    'cron' => [
        'secret' => env('CRON_SECRET'),
        'max_seconds' => (int) env('CRON_MAX_SECONDS', 50),
    ],

    /*
    | A developer aid, honoured only in the local environment: feature keys (comma separated) whose switches the
    | Feature control page treats as ordinary, so the cockpit can be tried while every real feature is still core.
    | Production ignores it.
    */
    'preview_unlock' => env('QISTAS_PREVIEW_UNLOCK', ''),
];
