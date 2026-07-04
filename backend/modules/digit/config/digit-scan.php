<?php

return [
    'module_enabled' => env('DIGIT_SCAN_MODULE_ENABLED', false),
    'staging_mode' => env('DIGIT_SCAN_STAGING_MODE', false),
    'lock_ttl_seconds' => env('DIGIT_SCAN_LOCK_TTL', 10),
    'idempotency_ttl_seconds' => env('DIGIT_SCAN_IDEMPOTENCY_TTL', 86400),
    'validity_cache_ttl_seconds' => env('DIGIT_SCAN_VALIDITY_CACHE_TTL', 120),
];
