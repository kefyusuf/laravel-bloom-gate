<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task17FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Redis\FakeRedisClientException;

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

it('reports a registered filter without an active generation as a warning without creating state', function (): void {
    $definition = new Task17FilterDefinition([]);

    app()->instance(Task17FilterDefinition::class, $definition);
    config()->set('bloom-gate.filters', [
        'users.email' => [
            'enabled' => true,
            'definition' => Task17FilterDefinition::class,
            'capacity' => 1_000,
            'false_positive_rate' => 0.01,
        ],
    ]);

    $name = FilterName::fromString('users.email');

    expect(app(FilterControlStore::class)->read($name))->toBeNull();

    $exit = Artisan::call('bloom:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('WARN filter.users.email.active_layout')
        ->and($output)->toContain('WARN filter.users.email.active_semantics')
        ->and($output)->not->toContain('FAIL filter.users.email')
        ->and(app(FilterControlStore::class)->read($name))->toBeNull();
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

it('classifies an unreachable redis runtime before resolving filter infrastructure', function (): void {
    if (class_exists('RedisException', false) === false) {
        class_alias(FakeRedisClientException::class, 'RedisException');
    }

    app()->bind(
        'redis',
        static fn (): never => throw new RedisException(
            'secret-redis-endpoint must never be printed',
        ),
    );

    config()->set('bloom-gate.default', 'redis');
    config()->set(
        'bloom-gate.drivers.redis.trusted_negative_profile',
        'standalone-primary-durable-v1',
    );
    config()->set('bloom-gate.filters', []);

    $exit = Artisan::call('bloom:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('FAIL redis_reachable')
        ->and($output)->toContain('FAIL redis_version')
        ->and($output)->toContain('FAIL redis_topology')
        ->and($output)->toContain('FAIL redis_primary')
        ->and($output)->not->toContain('secret-redis-endpoint')
        ->and($output)->not->toContain(
            'Bloom Gate doctor failed because diagnostics could not be evaluated safely.',
        );
});
