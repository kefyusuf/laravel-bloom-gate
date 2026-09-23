<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\FilterName;

it('defines a framework neutral exact-name filter registry port', function (): void {
    $contract = new ReflectionClass(FilterRegistry::class);

    expect($contract->isInterface())->toBeTrue();

    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $contract->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($methods);

    expect($methods)->toBe([
        'get',
        'globalQueryOptimizationEnabled',
    ]);

    $get = $contract->getMethod('get');
    $parameters = $get->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getName())->toBe('name');

    $nameType = $parameters[0]->getType();
    $returnType = $get->getReturnType();
    $globalReturnType = $contract
        ->getMethod('globalQueryOptimizationEnabled')
        ->getReturnType();

    foreach ([
        [$nameType, FilterName::class],
        [$returnType, RegisteredFilter::class],
        [$globalReturnType, 'bool'],
    ] as [$type, $expected]) {
        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException('Expected FilterRegistry named types.');
        }

        expect($type->getName())->toBe($expected);
    }

    $source = file_get_contents(__DIR__.'/../../../src/Contracts/FilterRegistry.php');

    if ($source === false) {
        throw new RuntimeException('Unable to read FilterRegistry source.');
    }

    expect($source)->not->toContain('Illuminate\\');
});

it('models per-filter enabled state explicitly as query optimization only', function (): void {
    $registered = new ReflectionClass(RegisteredFilter::class);

    expect($registered->isReadOnly())->toBeTrue();

    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $registered->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($methods);

    expect($methods)->toBe([
        '__construct',
        'capacity',
        'definition',
        'falsePositiveRate',
        'name',
        'queryOptimizationEnabled',
    ]);

    $definitionType = $registered->getMethod('definition')->getReturnType();

    if (! $definitionType instanceof ReflectionNamedType) {
        throw new RuntimeException('Expected RegisteredFilter definition return type.');
    }

    expect($definitionType->getName())->toBe(FilterDefinition::class)
        ->and($registered->hasMethod('synchronizationEnabled'))->toBeFalse()
        ->and($registered->hasMethod('enabled'))->toBeFalse();
});
