<?php

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY', ''),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET', ''),

    /*
     | Customer charge = actual provider cost * markup.
     | String value, handled with bcmath (never float).
     */
    'markup' => env('PIXELFORGE_MARKUP', '1.25'),

    /*
     | Prices are NEVER hardcoded: they are fetched live from GET /pricing
     | and cached for this many minutes (plus a persisted last-known-good
     | snapshot in the database as a degraded fallback).
     */
    'pricing_cache_minutes' => env('PIXELFORGE_PRICING_CACHE_MINUTES', 15),

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retries' => [
            // Max attempts for the submission job (503/overload, network errors).
            'max_attempts' => 9,
            // Backoff seconds per attempt for retriable failures.
            'backoff' => [5, 10, 30, 60, 120, 300, 600],
            // Cap on a single server-provided Retry-After when handling 429.
            'retry_after_cap' => 120,
        ],
    ],

    /*
     | Webhook safety: reject events whose timestamp header drifts more than
     | this many seconds from server time.
     */
    'webhook' => [
        'tolerance_seconds' => 300,
    ],

    /*
     | Polling fallback while a generation is in flight.
     */
    'polling' => [
        'first_delay_seconds' => 90,
        'interval_seconds' => 30,
        'max_polls' => 20,
        // Scheduler sweep: sync generations still in flight after this.
        'stuck_after_minutes' => 3,
    ],

    'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
    'qualities' => ['low', 'medium', 'high'],

    'limits' => [
        'max_reference_images' => 4,
        'max_prompt_chars' => 4000,
    ],
];
