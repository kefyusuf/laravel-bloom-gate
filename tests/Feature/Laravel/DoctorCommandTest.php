<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task17FilterDefinition;

beforeEach(function (): void {
    config()->set('bloom-gate.default', 'memory');
    config()->set('bloom-gate.enabled', true);
    config()->set('bloom-gate.keyspace.prefix', 'lbg');
    config()->set('bloom-gate.filters', []);
});

it('exposes bloom doctor as a read-only preflight command', function (): void {
    $definition = new Task17FilterDefinition([
        'secret-one@example.test',
        'secret-two@example.test',
    ]);

    app()->instance(Task17FilterDefinition::class, $definition);
    config()->set('bloom-gate.filters', [
        'users.email' => [
            'enabled' => true,
            'definition' => Task17FilterDefinition::class,
            'capacity' => 1_000,
            'false_positive_rate' => 0.01,
        ],
    ]);

    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0)
        ->and(Artisan::call('bloom:activate', ['filter' => 'users.email']))->toBe(0);

    $name = FilterName::fromString('users.email');
    $before = app(FilterControlStore::class)->read($name);

    expect($before)->not->toBeNull();

    $exit = Artisan::call('bloom:doctor');
    $output = Artisan::output();
    $after = app(FilterControlStore::class)->read($name);

    expect($exit)->toBe(0)
        ->and($after)->toEqual($before)
        ->and($output)->toContain('PASS package_config')
        ->and($output)->toContain('PASS keyspace_prefix')
        ->and($output)->toContain('NOT_ENABLED trusted_negative_profile')
        ->and($output)->toContain('PASS filter.users.email.definition')
        ->and($output)->toContain('PASS filter.users.email.control_state')
        ->and($output)->toContain('PASS filter.users.email.active_layout')
        ->and($output)->toContain('PASS filter.users.email.active_semantics')
        ->and($output)->not->toContain('secret-one@example.test')
        ->and($output)->not->toContain('secret-two@example.test');
});

it('reports active semantic drift as a doctor failure without repairing it', function (): void {
    $definition = new Task17FilterDefinition(['one']);

    app()->instance(Task17FilterDefinition::class, $definition);
    config()->set('bloom-gate.filters', [
        'users.email' => [
            'enabled' => true,
            'definition' => Task17FilterDefinition::class,
            'capacity' => 1_000,
            'false_positive_rate' => 0.01,
        ],
    ]);

    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0)
        ->and(Artisan::call('bloom:activate', ['filter' => 'users.email']))->toBe(0);

    $definition->normalizer->semanticIdentity = 'task17-normalizer@doctor-drift';

    $exit = Artisan::call('bloom:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('FAIL filter.users.email.active_semantics');

    $state = app(FilterControlStore::class)->read(
        FilterName::fromString('users.email'),
    );

    expect($state?->activeVersion()?->value())->toBe(1)
        ->and($state?->candidateVersion())->toBeNull();
});

it('keeps doctor command discovery side-effect free', function (): void {
    app()->bind(
        'redis',
        static fn (): never => throw new RuntimeException(
            'Redis must not resolve while discovering bloom:doctor.',
        ),
    );

    config()->set('bloom-gate.default', 'redis');

    $exit = Artisan::call('list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('bloom:doctor');
});
