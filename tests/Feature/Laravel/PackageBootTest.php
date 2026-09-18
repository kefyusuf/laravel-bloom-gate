<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Kefyusuf\BloomGate\Laravel\BloomGateServiceProvider;

it('boots with safe default configuration', function (): void {
    expect(config('bloom-gate.enabled'))->toBeTrue()
        ->and(config('bloom-gate.default'))->toBe('redis')
        ->and(config('bloom-gate.filters'))->toBe([]);
});

it('registers the package configuration for publishing', function (): void {
    $paths = ServiceProvider::pathsToPublish(
        BloomGateServiceProvider::class,
        'bloom-gate-config',
    );

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0] ?? null)
        ->toBe(config_path('bloom-gate.php'));
});
