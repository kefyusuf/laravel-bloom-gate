<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Closure;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;

/** Deterministic barriers around the real atomic persistence operations. */
final class InterleavingLifecycleStore implements CoordinatedLifecycleStore
{
    /** @var Closure():void|null */
    public ?Closure $beforeSynchronization = null;

    /** @var Closure(CoordinatedLifecycleSnapshot):void|null */
    public ?Closure $afterMutation = null;

    public function __construct(private CoordinatedLifecycleStore $inner) {}

    public function read(FilterName $name): CoordinatedLifecycleSnapshot
    {
        return $this->inner->read($name);
    }

    public function claimOwnership(FilterName $name, ?FilterStateRevision $expectedControlRevision): CoordinatedLifecycleSnapshot
    {
        return $this->after($this->inner->claimOwnership($name, $expectedControlRevision));
    }

    public function compareAndSwapControl(FilterName $name, FilterControlState $nextControl, ?FilterStateRevision $expectedControlRevision, SynchronizationRevision $expectedSyncRevision): CoordinatedLifecycleSnapshot
    {
        return $this->after($this->inner->compareAndSwapControl($name, $nextControl, $expectedControlRevision, $expectedSyncRevision));
    }

    public function compareAndSwapSynchronization(FilterName $name, SynchronizationState $nextSynchronization, ?SynchronizationRevision $expectedSyncRevision, ?FilterStateRevision $expectedControlRevision): CoordinatedLifecycleSnapshot
    {
        $barrier = $this->beforeSynchronization;
        $this->beforeSynchronization = null;
        if ($barrier !== null) {
            $barrier();
        }

        return $this->after($this->inner->compareAndSwapSynchronization($name, $nextSynchronization, $expectedSyncRevision, $expectedControlRevision));
    }

    private function after(CoordinatedLifecycleSnapshot $snapshot): CoordinatedLifecycleSnapshot
    {
        if ($this->afterMutation !== null) {
            ($this->afterMutation)($snapshot);
        }

        return $snapshot;
    }
}
