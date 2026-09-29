<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
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
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryWriterSynchronizationStore;

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

/**
 * @param  list<int>  $targets
 */
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

/**
 * Execute one Memory mutation inside a Fiber and capture its terminal exception.
 *
 * Memory operations intentionally do not suspend inside the shared domain, so
 * starting fibers in opposite orders exercises the two legal serializations
 * without sleep-based timing.
 *
 * @param  callable(): void  $operation
 */
function wu02MemoryFiberResult(callable $operation): ?Throwable
{
    $fiber = new Fiber(static function () use ($operation): ?Throwable {
        try {
            $operation();

            return null;
        } catch (Throwable $exception) {
            return $exception;
        }
    });

    $fiber->start();

    $result = $fiber->getReturn();

    if ($result !== null && ! $result instanceof Throwable) {
        throw new RuntimeException('Expected Memory Fiber operation to return Throwable|null.');
    }

    return $result;
}

function wu02CorruptMemoryDomainEntry(
    MemoryCoordinationDomain $domain,
    string $property,
    string $filterKey,
    mixed $value,
): void {
    $reflection = new ReflectionProperty(
        MemoryCoordinationDomain::class,
        $property,
    );
    $entries = $reflection->getValue($domain);

    if (! is_array($entries)) {
        throw new RuntimeException('Expected Memory coordination test storage to be an array.');
    }

    $entries[$filterKey] = $value;

    $reflection->setValue($domain, $entries);
}

it('keeps ordinary control CAS valid while coordination has never been adopted', function (): void {
    $domain = new MemoryCoordinationDomain;
    $coordination = new MemoryCoordinatedLifecycleStore($domain);
    $control = new MemoryFilterControlStore($domain);
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

it('serializes legacy control CAS and ownership claim in both deterministic Fiber orderings', function (): void {
    $name = FilterName::fromString('users.email');

    $legacyDomain = new MemoryCoordinationDomain;
    $legacyLifecycle = new MemoryCoordinatedLifecycleStore($legacyDomain);
    $legacyControl = new MemoryFilterControlStore($legacyDomain);

    $legacyFirst = wu02MemoryFiberResult(static function () use ($legacyControl, $name): void {
        $legacyControl->compareAndSwap(
            $name,
            wu02MemoryControlState($name, 1),
            null,
        );
    });
    $claimSecond = wu02MemoryFiberResult(static function () use ($legacyLifecycle, $name): void {
        $legacyLifecycle->claimOwnership($name, null);
    });

    expect($legacyFirst)->toBeNull()
        ->and($claimSecond)->toBeInstanceOf(CoordinationWriteConflict::class);

    $ownershipDomain = new MemoryCoordinationDomain;
    $ownershipLifecycle = new MemoryCoordinatedLifecycleStore($ownershipDomain);
    $ownedControl = new MemoryFilterControlStore($ownershipDomain);

    $claimFirst = wu02MemoryFiberResult(static function () use ($ownershipLifecycle, $name): void {
        $ownershipLifecycle->claimOwnership($name, null);
    });
    $legacySecond = wu02MemoryFiberResult(static function () use ($ownedControl, $name): void {
        $ownedControl->compareAndSwap(
            $name,
            wu02MemoryControlState($name, 1),
            null,
        );
    });

    expect($claimFirst)->toBeNull()
        ->and($legacySecond)->toBeInstanceOf(CoordinationFenced::class);
});

it('keeps adoption pending observable while writer admission fails closed', function (): void {
    $domain = new MemoryCoordinationDomain;
    $coordination = new MemoryCoordinatedLifecycleStore($domain);
    $writer = new MemoryWriterSynchronizationStore($domain);
    $name = FilterName::fromString('users.email');

    $snapshot = $coordination->claimOwnership($name, null);

    expect($snapshot->ownershipClaimed())->toBeTrue()
        ->and($snapshot->control())->toBeNull()
        ->and($snapshot->synchronization())->toBeNull();

    expect(fn () => $writer->acquire(
        $name,
        WriterLeaseToken::fromString(str_repeat('c', 32)),
    ))->toThrow(CoordinationFenced::class);
});

it('serializes opposite-plane CAS through revision fencing in both orderings', function (): void {
    $name = FilterName::fromString('users.email');

    $controlFirstDomain = new MemoryCoordinationDomain;
    $controlFirst = new MemoryCoordinatedLifecycleStore($controlFirstDomain);
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

    $syncFirstDomain = new MemoryCoordinationDomain;
    $syncFirst = new MemoryCoordinatedLifecycleStore($syncFirstDomain);
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

it('orders writer acquire wholly before or wholly after epoch rotation without timing sleeps', function (): void {
    $name = FilterName::fromString('users.email');

    $acquireFirstDomain = new MemoryCoordinationDomain;
    $acquireFirstLifecycle = new MemoryCoordinatedLifecycleStore($acquireFirstDomain);
    $acquireFirstWriter = new MemoryWriterSynchronizationStore($acquireFirstDomain);
    $acquireFirstLifecycle->claimOwnership($name, null);
    $acquireFirstLifecycle->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(1, 1, [1]),
        null,
        null,
    );

    $oldLease = $acquireFirstWriter->acquire(
        $name,
        WriterLeaseToken::fromString(str_repeat('d', 32)),
    );
    $acquireFirstLifecycle->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(2, 2, [1]),
        SynchronizationRevision::fromInt(1),
        null,
    );

    expect($oldLease->epoch()->value())->toBe(1);

    $rotateFirstDomain = new MemoryCoordinationDomain;
    $rotateFirstLifecycle = new MemoryCoordinatedLifecycleStore($rotateFirstDomain);
    $rotateFirstWriter = new MemoryWriterSynchronizationStore($rotateFirstDomain);
    $rotateFirstLifecycle->claimOwnership($name, null);
    $rotateFirstLifecycle->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(1, 1, [1]),
        null,
        null,
    );
    $rotateFirstLifecycle->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(2, 2, [1]),
        SynchronizationRevision::fromInt(1),
        null,
    );

    $newLease = $rotateFirstWriter->acquire(
        $name,
        WriterLeaseToken::fromString(str_repeat('e', 32)),
    );

    expect($newLease->epoch()->value())->toBe(2);
});

it('fails closed on malformed raw Memory ownership synchronization lease and count state', function (): void {
    $name = FilterName::fromString('users.email');
    $filterKey = $name->value();

    $ownerDomain = new MemoryCoordinationDomain;
    $ownerLifecycle = new MemoryCoordinatedLifecycleStore($ownerDomain);
    wu02CorruptMemoryDomainEntry($ownerDomain, 'owners', $filterKey, 'invalid-owner');

    expect(fn () => $ownerLifecycle->read($name))
        ->toThrow(CoordinationStateCorrupt::class);

    $syncDomain = new MemoryCoordinationDomain;
    $syncLifecycle = new MemoryCoordinatedLifecycleStore($syncDomain);
    wu02CorruptMemoryDomainEntry($syncDomain, 'owners', $filterKey, true);
    wu02CorruptMemoryDomainEntry($syncDomain, 'synchronizations', $filterKey, 'invalid-sync');

    expect(fn () => $syncLifecycle->read($name))
        ->toThrow(CoordinationStateCorrupt::class);

    $leaseDomain = new MemoryCoordinationDomain;
    $leaseLifecycle = new MemoryCoordinatedLifecycleStore($leaseDomain);
    $leaseWriter = new MemoryWriterSynchronizationStore($leaseDomain);
    $leaseLifecycle->claimOwnership($name, null);
    $leaseLifecycle->compareAndSwapSynchronization(
        $name,
        wu02MemorySynchronizationState(1, 1, [1]),
        null,
        null,
    );
    $leaseToken = WriterLeaseToken::fromString(str_repeat('f', 32));
    wu02CorruptMemoryDomainEntry(
        $leaseDomain,
        'leases',
        $filterKey,
        [$leaseToken->value() => 'invalid-lease'],
    );

    expect(fn () => $leaseWriter->markPrepared($name, $leaseToken))
        ->toThrow(CoordinationStateCorrupt::class);

    $countDomain = new MemoryCoordinationDomain;
    $countWriter = new MemoryWriterSynchronizationStore($countDomain);
    wu02CorruptMemoryDomainEntry(
        $countDomain,
        'counts',
        $filterKey,
        [1 => -1],
    );

    expect(fn () => $countWriter->activeWriterCount(
        $name,
        SynchronizationEpoch::fromInt(1),
    ))->toThrow(CoordinationStateCorrupt::class);
});
