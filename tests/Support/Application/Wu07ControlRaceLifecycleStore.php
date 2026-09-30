<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;

final class Wu07ControlRaceLifecycleStore implements CoordinatedLifecycleStore
{
    private bool $advanced = false;

    public function __construct(
        private CoordinatedLifecycleStore $inner,
        private MemoryFilterControlStore $legacyControl,
        private FilterControlState $nextControl,
    ) {}

    public function read(FilterName $name): CoordinatedLifecycleSnapshot
    {
        $snapshot = $this->inner->read($name);

        if (! $this->advanced) {
            $this->advanced = true;
            $this->legacyControl->compareAndSwap(
                $name,
                $this->nextControl,
                $snapshot->control()?->revision(),
            );
        }

        return $snapshot;
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
