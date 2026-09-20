<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Lifecycle\ActiveGenerationPolicy;

function task5PolicyState(
    HealthState $activeHealth,
): FilterControlState {
    $name = FilterName::fromString('products.sku');
    $active = FilterVersion::fromInt(1);

    return new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: $active,
        activeVersion: $active,
        candidateVersion: null,
        generations: [
            new GenerationControlState(
                $active,
                LifecycleState::Active,
                $activeHealth,
            ),
        ],
    );
}

it('selects only an active healthy current generation as probe eligible', function (): void {
    $state = task5PolicyState(HealthState::Healthy);

    expect((new ActiveGenerationPolicy)->eligibleVersion($state)?->value())->toBe(1);
});

it('rejects unhealthy active generations from probe eligibility', function (HealthState $health): void {
    expect((new ActiveGenerationPolicy)->eligibleVersion(
        task5PolicyState($health),
    ))->toBeNull();
})->with([
    HealthState::Degraded,
    HealthState::Stale,
    HealthState::Unavailable,
]);

it('does not treat a verified or shadow healthy candidate as an active eligible generation', function (
    LifecycleState $lifecycle,
): void {
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(1);
    $state = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: $candidate,
        activeVersion: null,
        candidateVersion: $candidate,
        generations: [
            new GenerationControlState(
                $candidate,
                $lifecycle,
                HealthState::Healthy,
            ),
        ],
    );

    expect((new ActiveGenerationPolicy)->eligibleVersion($state))->toBeNull();
})->with([
    LifecycleState::Verified,
    LifecycleState::Shadow,
]);

it('returns no eligible generation when the active pointer is missing', function (): void {
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(4);
    $state = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(2),
        lastAllocatedVersion: $version,
        activeVersion: null,
        candidateVersion: null,
        generations: [],
    );

    expect((new ActiveGenerationPolicy)->eligibleVersion($state))->toBeNull();
});

it('returns no eligible generation when control state is unavailable', function (): void {
    expect((new ActiveGenerationPolicy)->eligibleVersion(null))->toBeNull();
});
