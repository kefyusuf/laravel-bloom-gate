<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Lifecycle;

use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;

final class RecordingFilterControlStore implements FilterControlStore
{
    public int $readCalls = 0;

    public int $compareAndSwapCalls = 0;

    public function __construct(
        private ?FilterControlState $state,
        private bool $conflictOnWrite = false,
    ) {}

    public function read(FilterName $name): ?FilterControlState
    {
        $this->readCalls++;

        return $this->state;
    }

    public function compareAndSwap(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void {
        $this->compareAndSwapCalls++;

        if ($this->conflictOnWrite) {
            throw new FilterControlWriteConflict(
                'Simulated stale control-state write.',
            );
        }

        $this->state = $next;
    }

    public function state(): ?FilterControlState
    {
        return $this->state;
    }
}
