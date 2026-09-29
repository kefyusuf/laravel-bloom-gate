<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use LogicException;

final class MemoryFilterControlStore implements ActiveGenerationSnapshotReader, FilterControlStore
{
    private MemoryCoordinationDomain $domain;

    public function __construct(
        ?MemoryCoordinationDomain $domain = null,
    ) {
        $this->domain = $domain ?? new MemoryCoordinationDomain;
    }

    public function read(FilterName $name): ?FilterControlState
    {
        return $this->domain->readControl($name);
    }

    public function readActive(FilterName $name): ?ActiveGenerationSnapshot
    {
        $state = $this->read($name);

        if ($state === null) {
            return null;
        }

        return $this->activeSnapshot($state);
    }

    public function compareAndSwap(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void {
        $this->domain->compareAndSwapLegacyControl(
            $name,
            $next,
            $expectedRevision,
        );
    }

    private function activeSnapshot(
        FilterControlState $state,
    ): ?ActiveGenerationSnapshot {
        $activeVersion = $state->activeVersion();

        if ($activeVersion === null) {
            return null;
        }

        foreach ($state->generations() as $generation) {
            if ($generation->version()->equals($activeVersion) === false) {
                continue;
            }

            return new ActiveGenerationSnapshot(
                filterName: $state->filterName(),
                revision: $state->revision(),
                activeVersion: $activeVersion,
                lifecycle: $generation->lifecycle(),
                health: $generation->health(),
            );
        }

        throw new LogicException(
            'Validated control state active generation must be tracked.',
        );
    }
}
