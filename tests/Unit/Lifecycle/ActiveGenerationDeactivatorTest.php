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
use Kefyusuf\BloomGate\Lifecycle\ActiveGenerationDeactivator;

final class Task5DeactivationConflictStore implements FilterControlStore
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

        throw new FilterControlWriteConflict('deactivation lost race');
    }
}

function task5SeedDeactivationStore(
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
    task5SeedDeactivationStore($store, $existing);

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
    task5SeedDeactivationStore($store, $existing);

    expect(fn () => (new ActiveGenerationDeactivator($store))->deactivate($name))
        ->toThrow(InvalidArgumentException::class);
});

it('performs only one cas attempt and surfaces a deactivation conflict', function (): void {
    $name = FilterName::fromString('products.sku');
    $active = FilterVersion::fromInt(1);
    $current = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(3),
        lastAllocatedVersion: $active,
        activeVersion: $active,
        candidateVersion: null,
        generations: [
            new GenerationControlState(
                $active,
                LifecycleState::Active,
                HealthState::Healthy,
            ),
        ],
    );

    $store = new Task5DeactivationConflictStore($current);

    expect(fn () => (new ActiveGenerationDeactivator($store))->deactivate($name))
        ->toThrow(FilterControlWriteConflict::class, 'deactivation lost race');

    expect($store->writes)->toBe(1);
});
