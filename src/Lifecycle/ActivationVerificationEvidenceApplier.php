<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\LifecycleState;

final class ActivationVerificationEvidenceApplier
{
    public function apply(
        FilterControlState $state,
        ActivationVerificationResult $evidence,
    ): FilterControlState {
        if ($evidence->status() !== ActivationVerificationStatus::Passed) {
            throw new InvalidArgumentException(
                'Only passed activation verification evidence may be applied.',
            );
        }

        if ($state->filterName()->equals($evidence->filterName()) === false) {
            throw new InvalidArgumentException(
                'Activation verification evidence belongs to a different filter.',
            );
        }

        $candidateVersion = $state->candidateVersion();

        if ($candidateVersion === null) {
            throw new InvalidArgumentException(
                'Activation verification evidence requires a current candidate generation.',
            );
        }

        if ($candidateVersion->equals($evidence->filterVersion()) === false) {
            throw new InvalidArgumentException(
                'Activation verification evidence belongs to a different candidate generation.',
            );
        }

        $candidateFound = false;
        $nextGenerations = [];

        foreach ($state->generations() as $generation) {
            if ($generation->version()->equals($candidateVersion) === false) {
                $nextGenerations[] = $generation;

                continue;
            }

            $candidateFound = true;

            if ($generation->lifecycle() !== LifecycleState::Shadow) {
                throw new InvalidArgumentException(
                    'Activation verification evidence may only verify a SHADOW candidate.',
                );
            }

            $nextGenerations[] = new GenerationControlState(
                version: $generation->version(),
                lifecycle: LifecycleState::Verified,
                health: $generation->health(),
            );
        }

        if ($candidateFound === false) {
            throw new InvalidArgumentException(
                'Current candidate generation is not tracked by the control state.',
            );
        }

        return new FilterControlState(
            filterName: $state->filterName(),
            revision: $state->revision()->next(),
            lastAllocatedVersion: $state->lastAllocatedVersion(),
            activeVersion: $state->activeVersion(),
            candidateVersion: $candidateVersion,
            generations: $nextGenerations,
        );
    }
}
