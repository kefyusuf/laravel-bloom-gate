<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownFilter;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Tests\Support\Laravel\CountingFilterDefinition;

function task8ConfigRepository(): Repository
{
    $config = app()->make(Repository::class);

    return $config;
}

function task8Registry(): FilterRegistry
{
    $registry = app()->make(FilterRegistry::class);

    return $registry;
}

/**
 * @return array{
 *     enabled: bool,
 *     definition: class-string,
 *     capacity: int,
 *     false_positive_rate: float
 * }
 */
function task8ValidFilterConfig(
    bool $enabled = true,
    int $capacity = 1_000_000,
    float $falsePositiveRate = 0.001,
): array {
    return [
        'enabled' => $enabled,
        'definition' => CountingFilterDefinition::class,
        'capacity' => $capacity,
        'false_positive_rate' => $falsePositiveRate,
    ];
}

beforeEach(function (): void {
    CountingFilterDefinition::reset();

    task8ConfigRepository()->set('bloom-gate.enabled', true);
    task8ConfigRepository()->set('bloom-gate.filters', []);
});

it('resolves dotted filter names as exact case-sensitive array keys', function (): void {
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => task8ValidFilterConfig(
            enabled: false,
            capacity: 1_000_000,
        ),
        'Users.Email' => task8ValidFilterConfig(
            enabled: true,
            capacity: 2_000_000,
        ),
    ]);

    $lower = task8Registry()->get(FilterName::fromString('users.email'));
    $mixed = task8Registry()->get(FilterName::fromString('Users.Email'));

    expect($lower->name()->value())->toBe('users.email')
        ->and($lower->queryOptimizationEnabled())->toBeFalse()
        ->and($lower->capacity())->toBe(1_000_000)
        ->and($mixed->name()->value())->toBe('Users.Email')
        ->and($mixed->queryOptimizationEnabled())->toBeTrue()
        ->and($mixed->capacity())->toBe(2_000_000);
});

it('throws a typed unknown-filter failure for missing exact names', function (): void {
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => task8ValidFilterConfig(),
    ]);

    expect(fn () => task8Registry()->get(
        FilterName::fromString('Users.Email'),
    ))->toThrow(UnknownFilter::class);
});

it('resolves definitions lazily through the laravel container', function (): void {
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => task8ValidFilterConfig(),
    ]);

    $containerResolutions = 0;

    app()->bind(
        CountingFilterDefinition::class,
        static function () use (&$containerResolutions): CountingFilterDefinition {
            $containerResolutions++;

            return new CountingFilterDefinition;
        },
    );

    $registry = task8Registry();

    expect(CountingFilterDefinition::$instances)->toBe(0)
        ->and($containerResolutions)->toBe(0);

    $registered = $registry->get(FilterName::fromString('users.email'));

    expect($registered->definition())->toBeInstanceOf(CountingFilterDefinition::class)
        ->and(CountingFilterDefinition::$instances)->toBe(1)
        ->and($containerResolutions)->toBe(1);
});

it('resolves global optimization independently from per-filter optimization', function (): void {
    task8ConfigRepository()->set('bloom-gate.enabled', false);
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => task8ValidFilterConfig(enabled: true),
    ]);

    $registry = task8Registry();
    $registered = $registry->get(FilterName::fromString('users.email'));

    expect($registry->globalQueryOptimizationEnabled())->toBeFalse()
        ->and($registered->queryOptimizationEnabled())->toBeTrue();
});

it('rejects invalid definition class configuration', function (): void {
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => [
            ...task8ValidFilterConfig(),
            'definition' => 'App\\Bloom\\MissingFilterDefinition',
        ],
    ]);

    expect(fn () => task8Registry()->get(
        FilterName::fromString('users.email'),
    ))->toThrow(InvalidConfiguration::class);
});

it('rejects resolved classes that do not implement FilterDefinition', function (): void {
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => [
            ...task8ValidFilterConfig(),
            'definition' => stdClass::class,
        ],
    ]);

    expect(fn () => task8Registry()->get(
        FilterName::fromString('users.email'),
    ))->toThrow(InvalidConfiguration::class);
});

it('rejects closure definitions so filter config remains cache compatible', function (): void {
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => [
            ...task8ValidFilterConfig(),
            'definition' => static fn (): CountingFilterDefinition => new CountingFilterDefinition,
        ],
    ]);

    expect(fn () => task8Registry()->get(
        FilterName::fromString('users.email'),
    ))->toThrow(InvalidConfiguration::class);
});

it('rejects invalid capacity configuration', function (mixed $capacity): void {
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => [
            ...task8ValidFilterConfig(),
            'capacity' => $capacity,
        ],
    ]);

    expect(fn () => task8Registry()->get(
        FilterName::fromString('users.email'),
    ))->toThrow(InvalidConfiguration::class);
})->with([
    'zero' => 0,
    'negative' => -1,
    'numeric string' => '1000',
    'float' => 1000.0,
    'boolean' => true,
]);

it('rejects invalid false-positive rate configuration', function (
    mixed $falsePositiveRate,
): void {
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => [
            ...task8ValidFilterConfig(),
            'false_positive_rate' => $falsePositiveRate,
        ],
    ]);

    expect(fn () => task8Registry()->get(
        FilterName::fromString('users.email'),
    ))->toThrow(InvalidConfiguration::class);
})->with([
    'zero' => 0.0,
    'one' => 1.0,
    'negative' => -0.1,
    'greater than one' => 1.1,
    'numeric string' => '0.001',
    'nan' => NAN,
    'positive infinity' => INF,
    'negative infinity' => -INF,
]);

it('rejects non-boolean global and per-filter enabled configuration', function (): void {
    task8ConfigRepository()->set('bloom-gate.enabled', 'yes');

    expect(
        fn (): bool => task8Registry()->globalQueryOptimizationEnabled(),
    )->toThrow(InvalidConfiguration::class);

    task8ConfigRepository()->set('bloom-gate.enabled', true);
    task8ConfigRepository()->set('bloom-gate.filters', [
        'users.email' => [
            ...task8ValidFilterConfig(),
            'enabled' => 1,
        ],
    ]);

    expect(fn () => task8Registry()->get(
        FilterName::fromString('users.email'),
    ))->toThrow(InvalidConfiguration::class);
});
