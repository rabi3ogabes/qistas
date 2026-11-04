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
    | The country a new workspace gets when sign-up can detect none (no CDN header, no country in the browser's time zone, no region in its language).
    | Two letters; the owner confirms or changes it on the first-run card. One of the countries above.
    */
    'default_country' => env('QISTAS_DEFAULT_COUNTRY', 'SA'),

    /*
    | The request header that carries the visitor's country, set by the CDN in front of the app (for example
    | "X-Vercel-IP-Country" on Vercel or "CF-IPCountry" behind Cloudflare). Event themes use it for visitors who are not
    | signed in. Leave it empty unless the CDN sets it on every request and strips it from the browser's own: a header a
    | browser could send itself must never decide anything.
    */
    'geo_header' => env('QISTAS_GEO_HEADER'),

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
        // Pro by card. Normally saved in /admin/billing; a value here wins (local testing with your own test keys).
        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            'publishable' => env('STRIPE_PUBLISHABLE_KEY'),
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
    | The sign-in record each person can read ("recent sign-ins"): how many days a record is kept before
    | `qistas:sign-ins:prune` (daily) removes it. Read when the command runs. A whole number of days, at least 1.
    */
    'sign_in_days' => (int) env('QISTAS_SIGN_IN_DAYS', 90),

    /*
    | How many addresses X-Forwarded-For may list before a sign-in counts as having passed through a proxy
    | ("forwarded_chain"). A normal visit already has the CDN and the host's own proxy in the list, and how many that is
    | depends on the set-up: after going live, check that a normal sign-in reads "proxy not detected" and raise this
    | number if it does not.
    */
    'proxy_chain_allowed' => (int) env('QISTAS_PROXY_CHAIN_ALLOWED', 2),

    /*
    | The error center (admin: what is breaking on the site, the API, the database and the phone app). `enabled` false
    | turns capture off and hides nothing already recorded. A query slower than slow_query_ms is recorded as a slow query.
    | Sample occurrences are kept occurrence_days; an issue is kept issue_days after it was last seen (a resolved or
    | ignored one 30 days). Read when `qistas:errors:prune` runs. Whole numbers of days, at least 1.
    |
    | scanner_paths and browser_noise_paths: case-insensitive patterns, each anchored at the start of the path (which always
    | starts with a slash and never has its query string). A 404, 403 or 405 on one of them is counted per day as scanner
    | noise and never becomes an issue, a sample or an e-mail. A real broken link (/blog/typo, /pricing-old) matches neither.
    */
    'errors' => [
        'enabled' => filter_var(env('QISTAS_ERRORS', true), FILTER_VALIDATE_BOOL),
        'slow_query_ms' => (int) env('QISTAS_SLOW_QUERY_MS', 3000),
        'occurrence_days' => (int) env('QISTAS_ERROR_OCCURRENCE_DAYS', 30),
        'issue_days' => (int) env('QISTAS_ERROR_ISSUE_DAYS', 90),

        // What only a scanner asks for: the site has none of these.
        'scanner_paths' => [
            '#^/(?:[^/]*/)*\.#i', // a dot file or folder at any depth: /.env, /.git/config, /.aws/credentials, /.DS_Store, /laravel/.env
            '#^/cgi-bin(?:/|$)#i',
            '#^/actuator(?:/|$)#i',
            '#^/_ignition(?:/|$)#i',
            '#^/server-(?:status|info)(?:/|$)#i',
            '#^/boaform(?:/|$)#i',
            '#^/vendor/#i',
            '#^/wp-#i', // /wp-login.php, /wp-admin/, /wp-content/...
            '#^/wordpress(?:/|$)#i',
            '#^/xmlrpc\.php#i',
            '#^/phpmyadmin#i',
            '#^/pma(?:/|$)#i',
            '#^/admin\.php#i',
            '#^/solr(?:/|$)#i',
            '#^/jenkins(?:/|$)#i',
            '#^/console(?:/|$)#i',
            // anything ending like this, at any depth: code, configuration, logs, backups and archives, source maps
            '#^/.*\.(?:php|sql|bak|old|swp|ini|log|yml|yaml|asp|aspx|jsp|cgi|zip|gz|tar|rar|7z|env|map)/?$#i',
        ],

        // What real browsers ask for by themselves, the site serving no such file: icons a phone looks for, a service
        // worker or manifest of another site's making, and everything under /.well-known/. A route that exists answers
        // these with a page, so this only ever turns a 404 into noise.
        'browser_noise_paths' => [
            '#^/apple-touch-icon[^/]*\.png$#i', // /apple-touch-icon.png, -precomposed.png, -120x120.png
            '#^/browserconfig\.xml$#i',
            '#^/service-worker\.js$#i',
            '#^/sw\.js$#i',
            '#^/manifest\.json$#i',
            '#^/manifest\.webmanifest$#i',
            '#^/\.well-known(?:/|$)#i',
        ],
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
    | Pushes to phones (Win Plan PP9). The Firebase project's service account file, as JSON or base64 of it, set as a
    | secret on the host by the owner. Without it pushes are only written to the log; the in-app inbox works either way.
    */
    'push' => [
        'fcm_credentials' => env('QISTAS_FCM_CREDENTIALS'),
    ],

    /*
    | A developer aid, honoured only in the local environment: feature keys (comma separated) whose switches the
    | Feature control page treats as ordinary, so the cockpit can be tried while every real feature is still core.
    | Production ignores it.
    */
    'preview_unlock' => env('QISTAS_PREVIEW_UNLOCK', ''),
];
