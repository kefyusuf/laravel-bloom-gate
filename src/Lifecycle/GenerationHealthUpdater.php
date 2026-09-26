<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;

final readonly class GenerationHealthUpdater
{
    public function __construct(
        private FilterControlStore $store,
    ) {}

    public function update(
        FilterName $name,
        FilterVersion $version,
        HealthState $health,
    ): FilterControlState {
        $current = $this->store->read($name);

        if ($current === null) {
            throw new InvalidArgumentException(
                'Cannot update generation health without control state.',
            );
        }

        $generationFound = false;
        $nextGenerations = [];

        foreach ($current->generations() as $generation) {
            if ($generation->version()->equals($version) === false) {
                $nextGenerations[] = $generation;

                continue;
            }

            $generationFound = true;
            $nextGenerations[] = new GenerationControlState(
                version: $generation->version(),
                lifecycle: $generation->lifecycle(),
                health: $health,
            );
        }

        if ($generationFound === false) {
            throw new InvalidArgumentException(
                'Requested generation is not tracked by the control state.',
            );
        }

        $next = new FilterControlState(
            filterName: $current->filterName(),
            revision: $current->revision()->next(),
            lastAllocatedVersion: $current->lastAllocatedVersion(),
            activeVersion: $current->activeVersion(),
            candidateVersion: $current->candidateVersion(),
            generations: $nextGenerations,
        );

        $this->store->compareAndSwap(
            $name,
            $next,
            $current->revision(),
        );

        return $next;
    }
}
