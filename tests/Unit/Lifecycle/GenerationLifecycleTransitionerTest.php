<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;
use Kefyusuf\BloomGate\Tests\Support\Lifecycle\RecordingFilterControlStore;

function task7LifecycleState(
    LifecycleState $candidateLifecycle = LifecycleState::Configured,
    HealthState $candidateHealth = HealthState::Degraded,
): FilterControlState {
    return new FilterControlState(
        filterName: FilterName::fromString('users.email'),
        revision: FilterStateRevision::fromInt(7),
        lastAllocatedVersion: FilterVersion::fromInt(2),
        activeVersion: FilterVersion::fromInt(1),
        candidateVersion: FilterVersion::fromInt(2),
        generations: [
            new GenerationControlState(
                version: FilterVersion::fromInt(1),
                lifecycle: LifecycleState::Active,
                health: HealthState::Healthy,
            ),
            new GenerationControlState(
                version: FilterVersion::fromInt(2),
                lifecycle: $candidateLifecycle,
                health: $candidateHealth,
            ),
        ],
    );
}

it('persists a legal generic lifecycle transition and preserves orthogonal state', function (): void {
    $store = new RecordingFilterControlStore(task7LifecycleState());
    $service = new GenerationLifecycleTransitioner(
        $store,
        new LifecycleTransitionPolicy,
    );

    $next = $service->transition(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        LifecycleState::Building,
    );

    expect($next->revision()->value())->toBe(8)
        ->and($next->activeVersion()?->value())->toBe(1)
        ->and($next->candidateVersion()?->value())->toBe(2)
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Active)
        ->and($next->generations()[0]->health())->toBe(HealthState::Healthy)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Building)
        ->and($next->generations()[1]->health())->toBe(HealthState::Degraded)
        ->and($store->readCalls)->toBe(1)
        ->and($store->compareAndSwapCalls)->toBe(1);
});

it('delegates legality to the existing generic lifecycle policy', function (
    LifecycleState $from,
    LifecycleState $to,
): void {
    $store = new RecordingFilterControlStore(task7LifecycleState($from));
    $service = new GenerationLifecycleTransitioner(
        $store,
        new LifecycleTransitionPolicy,
    );

    expect(fn () => $service->transition(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        $to,
    ))->toThrow(InvalidArgumentException::class)
        ->and($store->compareAndSwapCalls)->toBe(0);
})->with([
    'evidence only shadow to verified' => [
        LifecycleState::Shadow,
        LifecycleState::Verified,
    ],
    'promotion only verified to active' => [
        LifecycleState::Verified,
        LifecycleState::Active,
    ],
    'illegal configured to shadow' => [
        LifecycleState::Configured,
        LifecycleState::Shadow,
    ],
]);

it('requires control state and the exact tracked generation', function (): void {
    $name = FilterName::fromString('users.email');

    $missingState = new RecordingFilterControlStore(null);
    $missingStateService = new GenerationLifecycleTransitioner(
        $missingState,
        new LifecycleTransitionPolicy,
    );

    expect(fn () => $missingStateService->transition(
        $name,
        FilterVersion::fromInt(1),
        LifecycleState::Building,
    ))->toThrow(InvalidArgumentException::class)
        ->and($missingState->compareAndSwapCalls)->toBe(0);

    $missingGeneration = new RecordingFilterControlStore(task7LifecycleState());
    $missingGenerationService = new GenerationLifecycleTransitioner(
        $missingGeneration,
        new LifecycleTransitionPolicy,
    );

    expect(fn () => $missingGenerationService->transition(
        $name,
        FilterVersion::fromInt(3),
        LifecycleState::Building,
    ))->toThrow(InvalidArgumentException::class)
        ->and($missingGeneration->compareAndSwapCalls)->toBe(0);
});

it('clears only a candidate pointer when a legal retirement would otherwise violate control invariants', function (): void {
    $store = new RecordingFilterControlStore(
        task7LifecycleState(LifecycleState::Building),
    );
    $service = new GenerationLifecycleTransitioner(
        $store,
        new LifecycleTransitionPolicy,
    );

    $next = $service->transition(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        LifecycleState::Retired,
    );

    expect($next->activeVersion()?->value())->toBe(1)
        ->and($next->candidateVersion())->toBeNull()
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Active)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Retired)
        ->and($next->generations()[1]->health())->toBe(HealthState::Degraded);
});

it('clears only the active pointer when retiring the active generation', function (): void {
    $state = new FilterControlState(
        filterName: FilterName::fromString('users.email'),
        revision: FilterStateRevision::fromInt(4),
        lastAllocatedVersion: FilterVersion::fromInt(2),
        activeVersion: FilterVersion::fromInt(1),
        candidateVersion: FilterVersion::fromInt(2),
        generations: [
            new GenerationControlState(
                FilterVersion::fromInt(1),
                LifecycleState::Active,
                HealthState::Stale,
            ),
            new GenerationControlState(
                FilterVersion::fromInt(2),
                LifecycleState::Shadow,
                HealthState::Healthy,
            ),
        ],
    );
    $store = new RecordingFilterControlStore($state);
    $service = new GenerationLifecycleTransitioner(
        $store,
        new LifecycleTransitionPolicy,
    );

    $next = $service->transition(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(1),
        LifecycleState::Retired,
    );

    expect($next->activeVersion())->toBeNull()
        ->and($next->candidateVersion()?->value())->toBe(2)
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Retired)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Shadow);
});

it('surfaces stale cas conflicts after exactly one read and one write attempt', function (): void {
    $store = new RecordingFilterControlStore(
        task7LifecycleState(),
        conflictOnWrite: true,
    );
    $service = new GenerationLifecycleTransitioner(
        $store,
        new LifecycleTransitionPolicy,
    );

    expect(fn () => $service->transition(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        LifecycleState::Building,
    ))->toThrow(FilterControlWriteConflict::class)
        ->and($store->readCalls)->toBe(1)
        ->and($store->compareAndSwapCalls)->toBe(1);
});

it('depends only on control persistence and the existing lifecycle policy', function (): void {
    $constructor = (new ReflectionClass(
        GenerationLifecycleTransitioner::class,
    ))->getConstructor();

    if ($constructor === null) {
        throw new RuntimeException('Expected lifecycle transitioner constructor.');
    }

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(2);

    $expected = [
        FilterControlStore::class,
        LifecycleTransitionPolicy::class,
    ];

    foreach ($parameters as $index => $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException('Expected named lifecycle transitioner dependencies.');
        }

        expect($type->getName())->toBe($expected[$index]);
    }
});
