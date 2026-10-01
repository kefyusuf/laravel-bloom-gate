<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use LogicException;
use Kefyusuf\BloomGate\Application\SynchronizationFencedControlStore;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;

final class Wu09PromotionWinsLifecycleStore implements CoordinatedLifecycleStore
{
    private bool $promoted = false;

    public function __construct(
        private CoordinatedLifecycleStore $inner,
    ) {}

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
        if (! $this->promoted) {
            $this->promoted = true;
            $snapshot = $this->inner->read($name);
            $control = $snapshot->control();
            $synchronization = $snapshot->synchronization();
            $candidate = $control?->candidateVersion();

            if ($control === null || $synchronization === null || $candidate === null) {
                throw new LogicException('WU-09 promotion race requires a current candidate.');
            }

            (new CandidatePromoter(
                new SynchronizationFencedControlStore(
                    lifecycle: $this->inner,
                    filterName: $name,
                    expectedSynchronizationRevision: $synchronization->revision(),
                ),
            ))->promote(
                $name,
                $candidate,
            );
        }

        return $this->inner->compareAndSwapSynchronization(
            $name,
            $nextSynchronization,
            $expectedSyncRevision,
            $expectedControlRevision,
        );
    }
}
