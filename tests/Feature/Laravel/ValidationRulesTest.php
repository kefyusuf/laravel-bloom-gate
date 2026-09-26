<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kefyusuf\BloomGate\Laravel\Validation\BloomExists;
use Kefyusuf\BloomGate\Laravel\Validation\BloomUnique;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task16FilterDefinition;
use ReflectionClass;
use RuntimeException;

beforeEach(function (): void {
    config()->set('bloom-gate.default', 'memory');
    config()->set('bloom-gate.enabled', false);
    config()->set('bloom-gate.filters', [
        'users.email' => [
            'enabled' => true,
            'definition' => Task16FilterDefinition::class,
            'capacity' => 1_000_000,
            'false_positive_rate' => 0.001,
        ],
    ]);
});

it('passes BloomUnique only when the authoritative-correct result does not exist', function (
    bool $authoritativeExists,
    bool $expectedPass,
): void {
    $definition = new Task16FilterDefinition($authoritativeExists);

    app()->instance(Task16FilterDefinition::class, $definition);

    $validator = Validator::make(
        ['email' => 'person@example.test'],
        ['email' => [new BloomUnique('users.email')]],
    );

    expect($validator->passes())->toBe($expectedPass)
        ->and($definition->normalizer->calls)->toBe(1)
        ->and($definition->authoritativeSet->existsCalls)->toBe(1);
})->with([
    'absent is unique' => [false, true],
    'present is not unique' => [true, false],
]);

it('makes BloomExists the inverse of BloomUnique', function (
    bool $authoritativeExists,
    bool $expectedPass,
): void {
    $definition = new Task16FilterDefinition($authoritativeExists);

    app()->instance(Task16FilterDefinition::class, $definition);

    $validator = Validator::make(
        ['email' => 'person@example.test'],
        ['email' => [new BloomExists('users.email')]],
    );

    expect($validator->passes())->toBe($expectedPass)
        ->and($definition->normalizer->calls)->toBe(1)
        ->and($definition->authoritativeSet->existsCalls)->toBe(1);
})->with([
    'present exists' => [true, true],
    'absent does not exist' => [false, false],
]);

it('keeps QueryGate bypass and authoritative fallback transparent to validation rules', function (): void {
    $definition = new Task16FilterDefinition(true);

    app()->instance(Task16FilterDefinition::class, $definition);

    config()->set('bloom-gate.enabled', false);

    $validator = Validator::make(
        ['external_id' => 42],
        ['external_id' => [new BloomExists('users.email')]],
    );

    expect($validator->passes())->toBeTrue()
        ->and($definition->normalizer->calls)->toBe(1)
        ->and($definition->authoritativeSet->existsCalls)->toBe(1);
});

it('keeps validation rules as thin QueryGate adapters without driver access', function (): void {
    foreach ([
        __DIR__.'/../../../src/Laravel/Validation/BloomUnique.php',
        __DIR__.'/../../../src/Laravel/Validation/BloomExists.php',
    ] as $path) {
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException('Unable to read Task 16 validation adapter.');
        }

        expect($source)->toContain('QueryGate')
            ->and($source)->not->toContain('BloomDriver')
            ->and($source)->not->toContain('FilterControlStore')
            ->and($source)->not->toContain('AuthorizedProbe');
    }
});

it('does not silently claim native unique-rule customization in M5', function (): void {
    foreach ([BloomUnique::class, BloomExists::class] as $rule) {
        $reflection = new ReflectionClass($rule);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull()
            ->and($constructor?->getNumberOfRequiredParameters())->toBe(1)
            ->and($reflection->hasMethod('ignore'))->toBeFalse()
            ->and($reflection->hasMethod('where'))->toBeFalse()
            ->and($reflection->hasMethod('withoutTrashed'))->toBeFalse()
            ->and($reflection->hasMethod('onlyTrashed'))->toBeFalse();
    }
});
