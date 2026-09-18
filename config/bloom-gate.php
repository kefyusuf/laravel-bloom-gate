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
        ],

        'memory' => [],
    ],

    'keyspace' => [
        'prefix' => env('BLOOM_GATE_PREFIX', 'lbg'),
    ],

    'build' => [
        'chunk_size' => 1000,
    ],

    'filters' => [],
];
