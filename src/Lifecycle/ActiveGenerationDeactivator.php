<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\LifecycleState;

final readonly class ActiveGenerationDeactivator
{
    private FilterControlStore $store;

    public function __construct(FilterControlStore $store)
    {
        $this->store = $store;
    }

    public function deactivate(FilterName $name): FilterControlState
    {
        $current = $this->store->read($name);

        if ($current === null) {
            throw new InvalidArgumentException(
                'Cannot deactivate an active generation without control state.',
            );
        }

        $activeVersion = $current->activeVersion();

        if ($activeVersion === null) {
            throw new InvalidArgumentException(
                'Cannot deactivate because there is no current active generation.',
            );
        }

        $activeFound = false;
        $nextGenerations = [];

        foreach ($current->generations() as $generation) {
            if ($generation->version()->equals($activeVersion) === false) {
                $nextGenerations[] = $generation;

                continue;
            }

            $activeFound = true;
            $nextGenerations[] = new GenerationControlState(
                version: $generation->version(),
                lifecycle: LifecycleState::Retired,
                health: $generation->health(),
            );
        }

        if ($activeFound === false) {
            throw new InvalidArgumentException(
                'Current active generation is not tracked by the control state.',
            );
        }

        $next = new FilterControlState(
            filterName: $current->filterName(),
            revision: $current->revision()->next(),
            lastAllocatedVersion: $current->lastAllocatedVersion(),
            activeVersion: null,
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
