<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Laravel\BloomGateServiceProvider;
use Kefyusuf\BloomGate\Tests\Support\Laravel\CountingFilterDefinition;

it('boots with safe default configuration', function (): void {
    expect(config('bloom-gate.enabled'))->toBeTrue()
        ->and(config('bloom-gate.default'))->toBe('redis')
        ->and(config('bloom-gate.drivers.redis.trusted_negative_profile'))->toBeNull()
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

it('registers the registry lazily without instantiating configured definitions', function (): void {
    CountingFilterDefinition::reset();

    config()->set('bloom-gate.filters', [
        'users.email' => [
            'enabled' => true,
            'definition' => CountingFilterDefinition::class,
            'capacity' => 1_000_000,
            'false_positive_rate' => 0.001,
        ],
    ]);

    $application = app();

    $application->register(BloomGateServiceProvider::class, true);

    expect(CountingFilterDefinition::$instances)->toBe(0);

    $registry = $application->make(FilterRegistry::class);

    expect(CountingFilterDefinition::$instances)->toBe(0);
});

it('does not require a live redis or database connection during package registration', function (): void {
    config()->set('bloom-gate.drivers.redis.connection', 'definitely-missing');
    config()->set('database.default', 'definitely-missing');

    $application = app();

    $application->register(BloomGateServiceProvider::class, true);

    expect(true)->toBeTrue();
});
