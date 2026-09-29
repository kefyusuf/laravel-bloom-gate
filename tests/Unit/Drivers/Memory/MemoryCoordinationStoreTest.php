<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;

function wu02MemoryControlState(
    FilterName $name,
    int $revision,
): FilterControlState {
    $version = FilterVersion::fromInt(1);

    return new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt($revision),
        lastAllocatedVersion: $version,
        activeVersion: $version,
        candidateVersion: null,
        generations: [
            new GenerationControlState(
                version: $version,
                lifecycle: LifecycleState::Active,
                health: HealthState::Healthy,
            ),
        ],
    );
}

function wu02MemorySynchronizationState(
    int $revision,
    int $epoch,
    array $targets,
): SynchronizationState {
    return new SynchronizationState(
        revision: SynchronizationRevision::fromInt($revision),
        phase: SynchronizationPhase::Steady,
        currentEpoch: SynchronizationEpoch::fromInt($epoch),
        currentTargets: SynchronizationTargetSet::fromVersions(array_map(
            static fn (int $version): FilterVersion => FilterVersion::fromInt($version),
            $targets,
        )),
        candidateVersion: null,
        drainingEpoch: null,
    );
}

it('keeps ordinary control CAS valid while coordination has never been adopted', function (): void {
    $coordination = new MemoryCoordinationStore;
    $control = new MemoryFilterControlStore($coordination);
    $name = FilterName::fromString('users.email');

    $control->compareAndSwap(
        $name,
        wu02MemoryControlState($name, 1),
        null,
    );

    expect($control->read($name)?->revision()->value())->toBe(1)
        ->and($coordination->read($name)->ownershipClaimed())->toBeFalse()
        ->and($coordination->read($name)->synchronization())->toBeNull();
});

it('serializes legacy control CAS and ownership claim in either legal ordering', function (): void {
    $name = FilterName::fromString('users.email');

    $legacyFirst = new MemoryCoordinationStore;
    $legacyControl = new MemoryFilterControlStore($legacyFirst);
    $legacyControl->compareAndSwap(
        $name,
        wu02MemoryControlState($name, 1),
        null,
    );

    expect(fn () => $legacyFirst->claimOwnership($name, null))
        ->toThrow(CoordinationWriteConflict::class);

    $ownershipFirst = new MemoryCoordinationStore;
    $ownedControl = new MemoryFilterControlStore($ownershipFirst);
    $ownershipFirst->claimOwnership($name, null);

    expect(fn () => $ownedControl->compareAndSwap(
        $name,
        wu02MemoryControlState($name, 1),
        null,
    ))->toThrow(CoordinationFenced::class);
});

it('keeps adoption pending observable while writer admission fails closed', function (): void {
    $coordination = new MemoryCoordinationStore;
    $name = FilterName::fromString('users.email');

    $snapshot = $coordination->claimOwnership($name, null);

    expect($snapshot->ownershipClaimed())->toBeTrue()
        ->and($snapshot->control())->toBeNull()
        ->and($snapshot->synchronization())->toBeNull();

    expect(fn () => $coordination->acquire(
        $name,
        WriterLeaseToken::fromString(str_repeat('c', 32)),
    ))->toThrow(CoordinationFenced::class);
});

it('serializes opposite-plane CAS through revision fencing in both orderings', function (): void {
    $name = FilterName::fromString('users.email');

    $controlFirst = new MemoryCoordinationStore;
    $controlFirst->claimOwnership($name, null);
    $controlFirst->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(1, 1, []),
        null,
        null,
    );

    $controlFirst->compareAndSwapControl(
        $name,
        wu02MemoryControlState($name, 1),
        null,
        SynchronizationRevision::fromInt(1),
    );

    expect(fn () => $controlFirst->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(2, 2, [1]),
        SynchronizationRevision::fromInt(1),
        null,
    ))->toThrow(CoordinationWriteConflict::class);

    $syncFirst = new MemoryCoordinationStore;
    $syncFirst->claimOwnership($name, null);
    $syncFirst->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(1, 1, []),
        null,
        null,
    );

    $syncFirst->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(2, 2, [1]),
        SynchronizationRevision::fromInt(1),
        null,
    );

    expect(fn () => $syncFirst->compareAndSwapControl(
        $name,
        wu02MemoryControlState($name, 1),
        null,
        SynchronizationRevision::fromInt(1),
    ))->toThrow(CoordinationWriteConflict::class);
});

it('orders writer acquire wholly before or wholly after epoch rotation', function (): void {
    $name = FilterName::fromString('users.email');

    $acquireFirst = new MemoryCoordinationStore;
    $acquireFirst->claimOwnership($name, null);
    $acquireFirst->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(1, 1, [1]),
        null,
        null,
    );

    $oldLease = $acquireFirst->acquire(
        $name,
        WriterLeaseToken::fromString(str_repeat('d', 32)),
    );
    $acquireFirst->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(2, 2, [1]),
        SynchronizationRevision::fromInt(1),
        null,
    );

    expect($oldLease->epoch()->value())->toBe(1);

    $rotateFirst = new MemoryCoordinationStore;
    $rotateFirst->claimOwnership($name, null);
    $rotateFirst->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(1, 1, [1]),
        null,
        null,
    );
    $rotateFirst->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(2, 2, [1]),
        SynchronizationRevision::fromInt(1),
        null,
    );

    $newLease = $rotateFirst->acquire(
        $name,
        WriterLeaseToken::fromString(str_repeat('e', 32)),
    );

    expect($newLease->epoch()->value())->toBe(2);
});
