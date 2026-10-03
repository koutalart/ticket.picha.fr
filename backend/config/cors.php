<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Adjusted settings for cross-origin resource sharing to allow
    | communication between the React app and the API, ensuring
    | cookies can be set and sent with requests.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => (static function (): array {
        $parse = static fn(?string $value): array => array_values(array_filter(
            array_map(static fn(string $origin) => rtrim(trim($origin), '/'), explode(',', (string)$value)),
            static fn(string $origin) => $origin !== '' && $origin !== '*'
        ));

        return $parse(env('CORS_ALLOWED_ORIGINS')) ?: $parse(env('APP_FRONTEND_URL'));
    })(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Auth-Token', 'Set-Cookie'],

    'max_age' => 0,

    'supports_credentials' => true,
];

