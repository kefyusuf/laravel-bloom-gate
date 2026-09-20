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
use Kefyusuf\BloomGate\Core\LifecycleState;

final readonly class CandidatePromoter
{
    private FilterControlStore $store;

    public function __construct(FilterControlStore $store)
    {
        $this->store = $store;
    }

    public function promote(
        FilterName $name,
        FilterVersion $candidateVersion,
    ): FilterControlState {
        $current = $this->store->read($name);

        if ($current === null) {
            throw new InvalidArgumentException(
                'Cannot promote a candidate without control state.',
            );
        }

        $currentCandidate = $current->candidateVersion();

        if ($currentCandidate === null) {
            throw new InvalidArgumentException(
                'Cannot promote because there is no current candidate generation.',
            );
        }

        if ($currentCandidate->equals($candidateVersion) === false) {
            throw new InvalidArgumentException(
                'Requested promotion version is not the current candidate generation.',
            );
        }

        $activeVersion = $current->activeVersion();
        $candidateFound = false;
        $nextGenerations = [];

        foreach ($current->generations() as $generation) {
            if ($generation->version()->equals($candidateVersion)) {
                $candidateFound = true;

                if ($generation->lifecycle() !== LifecycleState::Verified) {
                    throw new InvalidArgumentException(
                        'Candidate generation must be VERIFIED before promotion.',
                    );
                }

                if ($generation->health() !== HealthState::Healthy) {
                    throw new InvalidArgumentException(
                        'Candidate generation must be HEALTHY before promotion.',
                    );
                }

                $nextGenerations[] = new GenerationControlState(
                    version: $generation->version(),
                    lifecycle: LifecycleState::Active,
                    health: $generation->health(),
                );

                continue;
            }

            if (
                $activeVersion !== null
                && $generation->version()->equals($activeVersion)
            ) {
                $nextGenerations[] = new GenerationControlState(
                    version: $generation->version(),
                    lifecycle: LifecycleState::Retired,
                    health: $generation->health(),
                );

                continue;
            }

            $nextGenerations[] = $generation;
        }

        if ($candidateFound === false) {
            throw new InvalidArgumentException(
                'Current candidate generation is not tracked by the control state.',
            );
        }

        $next = new FilterControlState(
            filterName: $current->filterName(),
            revision: $current->revision()->next(),
            lastAllocatedVersion: $current->lastAllocatedVersion(),
            activeVersion: $candidateVersion,
            candidateVersion: null,
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
