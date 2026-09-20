<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Lifecycle\ActiveGenerationDeactivator;

it('explicitly retires the current active generation without promoting the candidate', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $active = FilterVersion::fromInt(1);
    $candidate = FilterVersion::fromInt(2);
    $existing = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(5),
        lastAllocatedVersion: $candidate,
        activeVersion: $active,
        candidateVersion: $candidate,
        generations: [
            new GenerationControlState(
                $active,
                LifecycleState::Active,
                HealthState::Stale,
            ),
            new GenerationControlState(
                $candidate,
                LifecycleState::Verified,
                HealthState::Healthy,
            ),
        ],
    );
    $store->compareAndSwap($name, $existing, null);

    $next = (new ActiveGenerationDeactivator($store))->deactivate($name);

    expect($next->revision()->value())->toBe(6)
        ->and($next->activeVersion())->toBeNull()
        ->and($next->candidateVersion())->toBe($candidate)
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Retired)
        ->and($next->generations()[0]->health())->toBe(HealthState::Stale)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Verified)
        ->and($next->generations()[1]->health())->toBe(HealthState::Healthy);
});

it('rejects deactivation when there is no current active generation', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(1);
    $existing = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: $version,
        activeVersion: null,
        candidateVersion: null,
        generations: [],
    );
    $store->compareAndSwap($name, $existing, null);

    expect(fn () => (new ActiveGenerationDeactivator($store))->deactivate($name))
        ->toThrow(InvalidArgumentException::class);
});
