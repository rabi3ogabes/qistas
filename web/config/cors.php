<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // The API is called with bearer tokens, never cookies, so any origin may ask; set CORS_ALLOWED_ORIGINS
    // (comma-separated) to narrow it to your own web app.
    'allowed_origins' => env('CORS_ALLOWED_ORIGINS') ? array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS'))) : ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Accept-Language', 'Idempotency-Key', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After', 'Content-Language'],

    'max_age' => 600,

    'supports_credentials' => false,

];
