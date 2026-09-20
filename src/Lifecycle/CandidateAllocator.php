<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;

final readonly class CandidateAllocator
{
    private FilterControlStore $store;

    public function __construct(FilterControlStore $store)
    {
        $this->store = $store;
    }

    public function allocate(FilterName $name): FilterControlState
    {
        $current = $this->store->read($name);

        if ($current === null) {
            $version = FilterVersion::fromInt(1);
            $next = new FilterControlState(
                filterName: $name,
                revision: FilterStateRevision::fromInt(1),
                lastAllocatedVersion: $version,
                activeVersion: null,
                candidateVersion: $version,
                generations: [
                    new GenerationControlState(
                        version: $version,
                        lifecycle: LifecycleState::Configured,
                        health: HealthState::Unavailable,
                    ),
                ],
            );

            $this->store->compareAndSwap($name, $next, null);

            return $next;
        }

        if ($current->candidateVersion() !== null) {
            throw new InvalidArgumentException(
                'A candidate generation is already allocated for this filter.',
            );
        }

        $version = $current->lastAllocatedVersion()->next();
        $generations = $current->generations();
        $generations[] = new GenerationControlState(
            version: $version,
            lifecycle: LifecycleState::Configured,
            health: HealthState::Unavailable,
        );

        $next = new FilterControlState(
            filterName: $current->filterName(),
            revision: $current->revision()->next(),
            lastAllocatedVersion: $version,
            activeVersion: $current->activeVersion(),
            candidateVersion: $version,
            generations: $generations,
        );

        $this->store->compareAndSwap(
            $name,
            $next,
            $current->revision(),
        );

        return $next;
    }
}
