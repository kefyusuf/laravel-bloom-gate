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
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Tests\Support\Lifecycle\RecordingFilterControlStore;

function task7HealthState(): FilterControlState
{
    return new FilterControlState(
        filterName: FilterName::fromString('users.email'),
        revision: FilterStateRevision::fromInt(11),
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
                lifecycle: LifecycleState::Building,
                health: HealthState::Unavailable,
            ),
        ],
    );
}

it('persists only the requested generation health and increments revision once', function (): void {
    $store = new RecordingFilterControlStore(task7HealthState());
    $service = new GenerationHealthUpdater($store);

    $next = $service->update(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        HealthState::Healthy,
    );

    expect($next->revision()->value())->toBe(12)
        ->and($next->activeVersion()?->value())->toBe(1)
        ->and($next->candidateVersion()?->value())->toBe(2)
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Active)
        ->and($next->generations()[0]->health())->toBe(HealthState::Healthy)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Building)
        ->and($next->generations()[1]->health())->toBe(HealthState::Healthy)
        ->and($store->readCalls)->toBe(1)
        ->and($store->compareAndSwapCalls)->toBe(1);
});

it('allows health changes without lifecycle or pointer mutation', function (HealthState $health): void {
    $store = new RecordingFilterControlStore(task7HealthState());
    $service = new GenerationHealthUpdater($store);

    $next = $service->update(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(1),
        $health,
    );

    expect($next->activeVersion()?->value())->toBe(1)
        ->and($next->candidateVersion()?->value())->toBe(2)
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Active)
        ->and($next->generations()[0]->health())->toBe($health)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Building)
        ->and($next->generations()[1]->health())->toBe(HealthState::Unavailable);
})->with(HealthState::cases());

it('requires control state and exact tracked generation', function (): void {
    $name = FilterName::fromString('users.email');

    $missingState = new RecordingFilterControlStore(null);
    $missingStateService = new GenerationHealthUpdater($missingState);

    expect(fn () => $missingStateService->update(
        $name,
        FilterVersion::fromInt(1),
        HealthState::Healthy,
    ))->toThrow(InvalidArgumentException::class)
        ->and($missingState->compareAndSwapCalls)->toBe(0);

    $missingGeneration = new RecordingFilterControlStore(task7HealthState());
    $missingGenerationService = new GenerationHealthUpdater($missingGeneration);

    expect(fn () => $missingGenerationService->update(
        $name,
        FilterVersion::fromInt(3),
        HealthState::Healthy,
    ))->toThrow(InvalidArgumentException::class)
        ->and($missingGeneration->compareAndSwapCalls)->toBe(0);
});

it('surfaces stale cas conflicts without retrying', function (): void {
    $store = new RecordingFilterControlStore(
        task7HealthState(),
        conflictOnWrite: true,
    );
    $service = new GenerationHealthUpdater($store);

    expect(fn () => $service->update(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        HealthState::Healthy,
    ))->toThrow(FilterControlWriteConflict::class)
        ->and($store->readCalls)->toBe(1)
        ->and($store->compareAndSwapCalls)->toBe(1);
});

it('depends only on the control store and has no driver dependency', function (): void {
    $constructor = (new ReflectionClass(
        GenerationHealthUpdater::class,
    ))->getConstructor();

    if ($constructor === null) {
        throw new RuntimeException('Expected generation health updater constructor.');
    }

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(1);

    $type = $parameters[0]->getType();

    if (! $type instanceof ReflectionNamedType) {
        throw new RuntimeException('Expected named health updater dependency.');
    }

    expect($type->getName())->toBe(FilterControlStore::class);
});
