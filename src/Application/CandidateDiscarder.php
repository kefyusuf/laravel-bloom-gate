<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;

final readonly class CandidateDiscarder
{
    public function __construct(
        private FilterControlStore $control,
        private GenerationLifecycleTransitioner $transitions,
        private LegacyMutationGuard $legacyMutations,
    ) {}

    public function discard(FilterName $name): FilterControlState
    {
        $this->legacyMutations->assertAllowed($name);

        $state = $this->control->read($name);

        if ($state === null) {
            throw new InvalidArgumentException(
                'Cannot discard a candidate without filter control state.',
            );
        }

        $candidateVersion = $state->candidateVersion();

        if ($candidateVersion === null) {
            throw new InvalidArgumentException(
                'Cannot discard because there is no current candidate generation.',
            );
        }

        return $this->transitions->transition(
            $name,
            $candidateVersion,
            LifecycleState::Retired,
        );
    }
}
