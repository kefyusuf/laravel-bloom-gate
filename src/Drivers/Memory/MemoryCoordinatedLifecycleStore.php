<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;

final readonly class MemoryCoordinatedLifecycleStore implements CoordinatedLifecycleStore
{
    public function __construct(
        private MemoryCoordinationDomain $domain,
    ) {}

    public function read(FilterName $name): CoordinatedLifecycleSnapshot
    {
        return $this->domain->readLifecycle($name);
    }

    public function claimOwnership(
        FilterName $name,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        return $this->domain->claimOwnership(
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
        return $this->domain->compareAndSwapControl(
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
        return $this->domain->compareAndSwapSynchronization(
            $name,
            $nextSynchronization,
            $expectedSyncRevision,
            $expectedControlRevision,
        );
    }
}
