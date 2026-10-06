<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Global Optimization Switch
    |--------------------------------------------------------------------------
    |
    | Disabling the package optimization must never disable authoritative
    | application lookups. Disabled filters are bypassed.
    |
    */
    'enabled' => env('BLOOM_GATE_ENABLED', true),

    'default' => 'redis',

    'drivers' => [
        'redis' => [
            'connection' => env('BLOOM_GATE_REDIS_CONNECTION', 'default'),
            'trusted_negative_profile' => env(
                'BLOOM_GATE_REDIS_TRUSTED_NEGATIVE_PROFILE',
            ),
        ],

        'memory' => [],
    ],

    'keyspace' => [
        'prefix' => env('BLOOM_GATE_PREFIX', 'lbg'),
    ],

    'query' => [
        'descriptor_cache' => [
            'driver' => 'none',
            // Required for APCu: a unique application/environment/backend namespace.
            'namespace' => null,
            // Expiration bounds hint retention; each lookup still checks live Redis.
            'ttl' => 60,
        ],
    ],

    'build' => [
        'chunk_size' => 1000,
    ],

    // Per-filter 'coordination' may be null (legacy) or 'coordinated-v1'.
    // The declaration requires explicit adoption and complete writer coverage.
    'filters' => [],
];
