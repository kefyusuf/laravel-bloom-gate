<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;

it('keeps the original bloom driver surface unchanged', function (): void {
    $contract = new ReflectionClass(BloomDriver::class);
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $contract->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($methods);

    expect($methods)->toBe([
        'add',
        'destroy',
        'mightContain',
        'provision',
    ]);
});

it('defines the additive generation inspector contract exactly', function (): void {
    $contract = new ReflectionClass(BloomGenerationInspector::class);

    expect($contract->isInterface())->toBeTrue();

    $methods = $contract->getMethods(ReflectionMethod::IS_PUBLIC);

    expect($methods)->toHaveCount(1)
        ->and($methods[0]->getName())->toBe('layout');

    $parameters = $methods[0]->getParameters();

    expect($parameters)->toHaveCount(2)
        ->and($parameters[0]->getName())->toBe('name')
        ->and($parameters[1]->getName())->toBe('version');

    $firstType = $parameters[0]->getType();
    $secondType = $parameters[1]->getType();
    $returnType = $methods[0]->getReturnType();

    if (
        ! $firstType instanceof ReflectionNamedType
        || ! $secondType instanceof ReflectionNamedType
        || ! $returnType instanceof ReflectionNamedType
    ) {
        throw new RuntimeException('Expected generation inspector types to be named.');
    }

    expect($firstType->getName())->toBe(FilterName::class)
        ->and($secondType->getName())->toBe(FilterVersion::class)
        ->and($returnType->getName())->toBe(BloomLayout::class)
        ->and($returnType->allowsNull())->toBeTrue();
});

it('defines generation contract store around managed descriptors and fingerprint contracts', function (): void {
    $contract = new ReflectionClass(GenerationContractStore::class);

    expect($contract->isInterface())->toBeTrue();

    $read = $contract->getMethod('read');
    $bind = $contract->getMethod('bind');

    expect($read->getNumberOfParameters())->toBe(2)
        ->and($bind->getNumberOfParameters())->toBe(4);

    $readReturn = $read->getReturnType();
    $bindLayout = $bind->getParameters()[2]->getType();
    $bindContract = $bind->getParameters()[3]->getType();

    if (
        ! $readReturn instanceof ReflectionNamedType
        || ! $bindLayout instanceof ReflectionNamedType
        || ! $bindContract instanceof ReflectionNamedType
    ) {
        throw new RuntimeException('Expected generation contract store types to be named.');
    }

    expect($readReturn->getName())->toBe(ManagedGenerationDescriptor::class)
        ->and($readReturn->allowsNull())->toBeTrue()
        ->and($bindLayout->getName())->toBe(BloomLayout::class)
        ->and($bindContract->getName())->toBe(GenerationSemanticContract::class);
});

it('keeps semantic contract persistence fingerprint-only', function (): void {
    $contract = new ReflectionClass(GenerationSemanticContract::class);
    $constructor = $contract->getConstructor();

    if ($constructor === null) {
        throw new RuntimeException('Expected GenerationSemanticContract constructor.');
    }

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(3);

    $expected = [
        NormalizationFingerprint::class,
        AuthoritativeSetFingerprint::class,
        ConsistencyFingerprint::class,
    ];

    foreach ($parameters as $index => $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException('Expected semantic contract constructor types to be named.');
        }

        expect($type->getName())->toBe($expected[$index]);
    }
});

it('defines managed descriptor as layout plus semantic contract only', function (): void {
    $descriptor = new ReflectionClass(ManagedGenerationDescriptor::class);

    expect($descriptor->isReadOnly())->toBeTrue();

    $publicMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $descriptor->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($publicMethods);

    expect($publicMethods)->toBe([
        '__construct',
        'layout',
        'semanticContract',
    ]);
});
