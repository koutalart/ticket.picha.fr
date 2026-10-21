<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'httpsms' => [
        'base_url' => env('HTTPSMS_BASE_URL', 'https://api.httpsms.com/v1'),
        'timeout_seconds' => (int) env('HTTPSMS_TIMEOUT_SECONDS', 15),
    ],

    'meta' => [
        'graph_api_version' => env('META_GRAPH_API_VERSION', 'v24.0'),
        'timeout_seconds' => (int) env('META_CONVERSIONS_API_TIMEOUT', 10),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Canadian platform (Optional)
        'ca_secret_key' => env('STRIPE_CA_SECRET_KEY', env('STRIPE_SECRET_KEY')),
        'ca_public_key' => env('STRIPE_CA_PUBLIC_KEY', env('STRIPE_PUBLIC_KEY')),
        'ca_webhook_secret' => env('STRIPE_CA_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),

        // Irish platform (Optional)
        'ie_secret_key' => env('STRIPE_IE_SECRET_KEY', env('STRIPE_SECRET_KEY')),
        'ie_public_key' => env('STRIPE_IE_PUBLIC_KEY', env('STRIPE_PUBLIC_KEY')),
        'ie_webhook_secret' => env('STRIPE_IE_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),

        // Primary platform for new organizers
        'primary_platform' => env('STRIPE_PRIMARY_PLATFORM'),
    ],
    'open_exchange_rates' => [
        'app_id' => env('OPEN_EXCHANGE_RATES_APP_ID'),
    ],
    // Event creation assistant (poster reading, description rewriting). Disabled when the key is empty.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        // SMS reply assistant: maximum Claude calls per organizer and per day (0 = no limit).
        'sms_assistant_daily_limit' => (int) env('SMS_ASSISTANT_DAILY_LIMIT', 150),
    ],
];
