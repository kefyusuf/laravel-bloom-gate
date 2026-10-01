<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use LogicException;

final class Wu09PublicationWinsLifecycleStore implements CoordinatedLifecycleStore
{
    private bool $published = false;

    public function __construct(
        private CoordinatedLifecycleStore $inner,
    ) {
        // Explicit WU-09 race-fixture constructor body.
    }

    public function read(FilterName $name): CoordinatedLifecycleSnapshot
    {
        return $this->inner->read($name);
    }

    public function claimOwnership(
        FilterName $name,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        return $this->inner->claimOwnership(
            $name,
            $expectedControlRevision,
        );
    }

    public function compareAndSwapControl(
        FilterName $name,
        FilterControlState $nextControl,
        ?FilterStateRevision $expectedControlRevision,
        SynchronizationRevision $expectedSyncRevision,
    ): CoordinatedLifecycleSnapshot {
        if ($this->published === false) {
            $this->published = true;
            $snapshot = $this->inner->read($name);
            $control = $snapshot->control();
            $synchronization = $snapshot->synchronization();
            $candidate = $control?->candidateVersion();

            if ($control === null || $synchronization === null || $candidate === null) {
                throw new LogicException('WU-09 publication race requires a current candidate.');
            }

            $versions = [$candidate];
            $active = $control->activeVersion();

            if ($active !== null) {
                $versions[] = $active;
            }

            usort(
                $versions,
                static fn (FilterVersion $left, FilterVersion $right): int => $left->value() <=> $right->value(),
            );

            $published = new SynchronizationState(
                revision: $synchronization->revision()->next(),
                phase: SynchronizationPhase::DrainingPreReconcile,
                currentEpoch: $synchronization->currentEpoch()->next(),
                currentTargets: SynchronizationTargetSet::fromVersions($versions),
                candidateVersion: $candidate,
                drainingEpoch: $synchronization->currentEpoch(),
            );

            $this->inner->compareAndSwapSynchronization(
                $name,
                $published,
                $synchronization->revision(),
                $control->revision(),
            );
        }

        return $this->inner->compareAndSwapControl(
            $name,
            $nextControl,
            $expectedControlRevision,
            $expectedSyncRevision,
        );
    }

    public function compareAndSwapSynchronization(
        FilterName $name,
        SynchronizationState $nextSynchronization,
        ?SynchronizationRevision $expectedSyncRevision,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        return $this->inner->compareAndSwapSynchronization(
            $name,
            $nextSynchronization,
            $expectedSyncRevision,
            $expectedControlRevision,
        );
    }
}
