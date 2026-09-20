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
use Kefyusuf\BloomGate\Lifecycle\CandidateAllocator;

function task5AllocationState(
    FilterName $name,
    int $revision,
    int $lastAllocated,
    ?int $active,
    ?int $candidate,
    array $generations,
): FilterControlState {
    return new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt($revision),
        lastAllocatedVersion: FilterVersion::fromInt($lastAllocated),
        activeVersion: $active === null ? null : FilterVersion::fromInt($active),
        candidateVersion: $candidate === null ? null : FilterVersion::fromInt($candidate),
        generations: $generations,
    );
}

it('allocates version one into an empty control store', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');

    $next = (new CandidateAllocator($store))->allocate($name);

    expect($next->revision()->value())->toBe(1)
        ->and($next->lastAllocatedVersion()->value())->toBe(1)
        ->and($next->activeVersion())->toBeNull()
        ->and($next->candidateVersion()?->value())->toBe(1)
        ->and($next->generations())->toHaveCount(1)
        ->and($next->generations()[0]->version()->value())->toBe(1)
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Configured)
        ->and($next->generations()[0]->health())->toBe(HealthState::Unavailable)
        ->and($store->read($name)?->candidateVersion()?->value())->toBe(1);
});

it('allocates strictly after the last allocated version and never reuses retired gaps', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $existing = task5AllocationState(
        name: $name,
        revision: 8,
        lastAllocated: 5,
        active: null,
        candidate: null,
        generations: [
            new GenerationControlState(
                FilterVersion::fromInt(1),
                LifecycleState::Retired,
                HealthState::Unavailable,
            ),
            new GenerationControlState(
                FilterVersion::fromInt(3),
                LifecycleState::Retired,
                HealthState::Degraded,
            ),
        ],
    );
    $store->compareAndSwap($name, $existing, null);

    $next = (new CandidateAllocator($store))->allocate($name);

    expect($next->revision()->value())->toBe(9)
        ->and($next->lastAllocatedVersion()->value())->toBe(6)
        ->and($next->candidateVersion()?->value())->toBe(6)
        ->and($next->generations())->toHaveCount(3)
        ->and($next->generations()[2]->version()->value())->toBe(6)
        ->and($next->generations()[2]->lifecycle())->toBe(LifecycleState::Configured)
        ->and($next->generations()[2]->health())->toBe(HealthState::Unavailable);
});

it('rejects allocating a second candidate without writing', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(2);
    $existing = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(4),
        lastAllocatedVersion: $candidate,
        activeVersion: null,
        candidateVersion: $candidate,
        generations: [
            new GenerationControlState(
                $candidate,
                LifecycleState::Shadow,
                HealthState::Healthy,
            ),
        ],
    );
    $store->compareAndSwap($name, $existing, null);

    expect(fn () => (new CandidateAllocator($store))->allocate($name))
        ->toThrow(InvalidArgumentException::class);

    expect($store->read($name)?->revision()->value())->toBe(4)
        ->and($store->read($name)?->candidateVersion()?->value())->toBe(2);
});

it('fails cleanly instead of wrapping the generation version at php int max', function (): void {
    $store = new MemoryFilterControlStore;
    $name = FilterName::fromString('products.sku');
    $max = FilterVersion::fromInt(PHP_INT_MAX);
    $existing = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: $max,
        activeVersion: null,
        candidateVersion: null,
        generations: [],
    );
    $store->compareAndSwap($name, $existing, null);

    expect(fn () => (new CandidateAllocator($store))->allocate($name))
        ->toThrow(OverflowException::class);

    expect($store->read($name)?->lastAllocatedVersion()->value())->toBe(PHP_INT_MAX)
        ->and($store->read($name)?->candidateVersion())->toBeNull();
});

it('performs only one cas attempt and surfaces a write conflict to the caller', function (): void {
    $name = FilterName::fromString('products.sku');
    $current = task5AllocationState(
        name: $name,
        revision: 3,
        lastAllocated: 3,
        active: null,
        candidate: null,
        generations: [],
    );

    $store = new class($current) implements FilterControlStore
    {
        public int $reads = 0;

        public int $writes = 0;

        public function __construct(
            private FilterControlState $current,
        ) {}

        public function read(FilterName $name): ?FilterControlState
        {
            $this->reads++;

            return $this->current;
        }

        public function compareAndSwap(
            FilterName $name,
            FilterControlState $next,
            ?FilterStateRevision $expectedRevision,
        ): void {
            $this->writes++;

            throw new FilterControlWriteConflict('simulated concurrent winner');
        }
    };

    expect(fn () => (new CandidateAllocator($store))->allocate($name))
        ->toThrow(FilterControlWriteConflict::class, 'simulated concurrent winner');

    expect($store->reads)->toBe(1)
        ->and($store->writes)->toBe(1);
});
