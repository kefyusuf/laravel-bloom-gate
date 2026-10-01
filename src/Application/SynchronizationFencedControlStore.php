<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;

final readonly class SynchronizationFencedControlStore implements FilterControlStore
{
    public function __construct(
        private CoordinatedLifecycleStore $lifecycle,
        private FilterName $filterName,
        private SynchronizationRevision $expectedSynchronizationRevision,
    ) {}

    public function read(FilterName $name): ?FilterControlState
    {
        $this->assertFilterName($name);

        $snapshot = $this->lifecycle->read($name);
        $synchronization = $snapshot->synchronization();

        if (! $snapshot->ownershipClaimed() || $synchronization === null) {
            throw new CoordinationFenced(
                'Coordinated control access requires durable ownership and synchronization state.',
            );
        }

        if (
            $synchronization->revision()->equals(
                $this->expectedSynchronizationRevision,
            ) === false
        ) {
            throw new CoordinationWriteConflict(
                'Synchronization revision changed while coordinated control access was pinned.',
            );
        }

        return $snapshot->control();
    }

    public function compareAndSwap(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void {
        $this->assertFilterName($name);

        $this->lifecycle->compareAndSwapControl(
            $name,
            $next,
            $expectedRevision,
            $this->expectedSynchronizationRevision,
        );
    }

    private function assertFilterName(FilterName $name): void
    {
        if ($this->filterName->equals($name)) {
            return;
        }

        throw new InvalidArgumentException(
            'Synchronization-fenced control store is pinned to a different filter.',
        );
    }
}
