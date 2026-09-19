<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;

it('retains generation version lifecycle and health exactly', function (): void {
    $version = FilterVersion::fromInt(7);
    $state = new GenerationControlState(
        version: $version,
        lifecycle: LifecycleState::Shadow,
        health: HealthState::Degraded,
    );

    expect($state->version())->toBe($version)
        ->and($state->lifecycle())->toBe(LifecycleState::Shadow)
        ->and($state->health())->toBe(HealthState::Degraded);
});

it('is an immutable state container without lifecycle or health policy methods', function (): void {
    $reflection = new ReflectionClass(GenerationControlState::class);

    $publicMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($publicMethods);

    expect($reflection->isReadOnly())->toBeTrue()
        ->and($publicMethods)->toBe([
            '__construct',
            'health',
            'lifecycle',
            'version',
        ]);
});
