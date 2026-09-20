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
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;

function task5PromotionState(
    FilterName $name,
    FilterVersion $candidate,
    LifecycleState $candidateLifecycle = LifecycleState::Verified,
    HealthState $candidateHealth = HealthState::Healthy,
): FilterControlState {
    $active = FilterVersion::fromInt(1);

    return new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(7),
        lastAllocatedVersion: $candidate,
        activeVersion: $active,
        candidateVersion: $candidate,
        generations: [
            new GenerationControlState(
                $active,
                LifecycleState::Active,
                HealthState::Degraded,
            ),
            new GenerationControlState(
                $candidate,
                $candidateLifecycle,
                $candidateHealth,
            ),
        ],
    );
}

function task5SeedPromotionStore(
    MemoryFilterControlStore $store,
    FilterControlState $target,
): void {
    $targetRevision = $target->revision()->value();

    for ($revision = 1; $revision <= $targetRevision; $revision++) {
        $snapshot = new FilterControlState(
            filterName: $target->filterName(),
            revision: FilterStateRevision::fromInt($revision),
            lastAllocatedVersion: $target->lastAllocatedVersion(),
            activeVersion: $target->activeVersion(),
            candidateVersion: $target->candidateVersion(),
            generations: $target->generations(),
        );

        $store->compareAndSwap(
            $target->filterName(),
            $snapshot,
            $revision === 1 ? null : FilterStateRevision::fromInt($revision - 1),
        );
    }
}

it('promotes the exact verified healthy candidate and retires the prior active generation', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(2);
    $existing = task5PromotionState($name, $candidate);
    task5SeedPromotionStore($store, $existing);

    $next = (new CandidatePromoter($store))->promote($name, $candidate);

    expect($next->revision()->value())->toBe(8)
        ->and($next->lastAllocatedVersion())->toBe($candidate)
        ->and($next->activeVersion())->toBe($candidate)
        ->and($next->candidateVersion())->toBeNull()
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Retired)
        ->and($next->generations()[0]->health())->toBe(HealthState::Degraded)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Active)
        ->and($next->generations()[1]->health())->toBe(HealthState::Healthy)
        ->and($existing->generations()[0]->lifecycle())->toBe(LifecycleState::Active)
        ->and($existing->generations()[1]->lifecycle())->toBe(LifecycleState::Verified);
});

it('promotes a verified healthy candidate when there is no prior active generation', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(1);
    $existing = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(2),
        lastAllocatedVersion: $candidate,
        activeVersion: null,
        candidateVersion: $candidate,
        generations: [
            new GenerationControlState(
                $candidate,
                LifecycleState::Verified,
                HealthState::Healthy,
            ),
        ],
    );
    task5SeedPromotionStore($store, $existing);

    $next = (new CandidatePromoter($store))->promote($name, $candidate);

    expect($next->activeVersion())->toBe($candidate)
        ->and($next->candidateVersion())->toBeNull()
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Active);
});

it('rejects promotion when there is no candidate', function (): void {
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
    task5SeedPromotionStore($store, $existing);

    expect(fn () => (new CandidatePromoter($store))->promote($name, $version))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects promotion when the requested candidate version differs', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(2);
    $existing = task5PromotionState($name, $candidate);
    task5SeedPromotionStore($store, $existing);

    expect(fn () => (new CandidatePromoter($store))->promote(
        $name,
        FilterVersion::fromInt(3),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects promotion unless the candidate is verified', function (LifecycleState $lifecycle): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(2);
    $existing = task5PromotionState(
        $name,
        $candidate,
        $lifecycle,
        HealthState::Healthy,
    );
    task5SeedPromotionStore($store, $existing);

    expect(fn () => (new CandidatePromoter($store))->promote($name, $candidate))
        ->toThrow(InvalidArgumentException::class);
})->with([
    LifecycleState::Configured,
    LifecycleState::Building,
    LifecycleState::Shadow,
]);

it('rejects promotion unless the verified candidate is healthy', function (HealthState $health): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(2);
    $existing = task5PromotionState(
        $name,
        $candidate,
        LifecycleState::Verified,
        $health,
    );
    task5SeedPromotionStore($store, $existing);

    expect(fn () => (new CandidatePromoter($store))->promote($name, $candidate))
        ->toThrow(InvalidArgumentException::class);
})->with([
    HealthState::Degraded,
    HealthState::Stale,
    HealthState::Unavailable,
]);

it('performs only one cas attempt and does not hide a promotion conflict', function (): void {
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(2);
    $current = task5PromotionState($name, $candidate);

    $store = new class($current) implements FilterControlStore
    {
        public int $writes = 0;

        public function __construct(
            private FilterControlState $current,
        ) {}

        public function read(FilterName $name): ?FilterControlState
        {
            if ($name->equals($this->current->filterName()) === false) {
                return null;
            }

            return $this->current;
        }

        public function compareAndSwap(
            FilterName $name,
            FilterControlState $next,
            ?FilterStateRevision $expectedRevision,
        ): void {
            $this->writes++;

            throw new FilterControlWriteConflict('promotion lost race');
        }
    };

    expect(fn () => (new CandidatePromoter($store))->promote($name, $candidate))
        ->toThrow(FilterControlWriteConflict::class, 'promotion lost race');

    expect($store->writes)->toBe(1);
});

it('depends only on the control store and has no bloom data plane dependency', function (): void {
    $constructor = new ReflectionMethod(CandidatePromoter::class, '__construct');
    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and((string) $parameters[0]->getType())
        ->toBe(FilterControlStore::class);
});
