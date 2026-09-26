<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\LifecycleState;

final readonly class GenerationLifecycleTransitioner
{
    public function __construct(
        private FilterControlStore $store,
        private LifecycleTransitionPolicy $policy,
    ) {}

    public function transition(
        FilterName $name,
        FilterVersion $version,
        LifecycleState $target,
    ): FilterControlState {
        $current = $this->store->read($name);

        if ($current === null) {
            throw new InvalidArgumentException(
                'Cannot transition a generation without control state.',
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
            $nextGenerations[] = $this->policy->transition(
                $generation,
                $target,
            );
        }

        if ($generationFound === false) {
            throw new InvalidArgumentException(
                'Requested generation is not tracked by the control state.',
            );
        }

        $activeVersion = $current->activeVersion();
        $candidateVersion = $current->candidateVersion();

        if ($target === LifecycleState::Retired) {
            if ($activeVersion !== null && $activeVersion->equals($version)) {
                $activeVersion = null;
            }

            if ($candidateVersion !== null && $candidateVersion->equals($version)) {
                $candidateVersion = null;
            }
        }

        $next = new FilterControlState(
            filterName: $current->filterName(),
            revision: $current->revision()->next(),
            lastAllocatedVersion: $current->lastAllocatedVersion(),
            activeVersion: $activeVersion,
            candidateVersion: $candidateVersion,
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
