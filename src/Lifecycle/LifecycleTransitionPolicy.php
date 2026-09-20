<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\LifecycleState;

final class LifecycleTransitionPolicy
{
    public function transition(
        GenerationControlState $current,
        LifecycleState $target,
    ): GenerationControlState {
        if ($this->allowsGenericTransition($current->lifecycle(), $target) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Lifecycle transition from %s to %s is not allowed by the generic M4 policy.',
                    $current->lifecycle()->name,
                    $target->name,
                ),
            );
        }

        return new GenerationControlState(
            version: $current->version(),
            lifecycle: $target,
            health: $current->health(),
        );
    }

    private function allowsGenericTransition(
        LifecycleState $from,
        LifecycleState $to,
    ): bool {
        return match ($from) {
            LifecycleState::Configured => $to === LifecycleState::Building
                || $to === LifecycleState::Retired,
            LifecycleState::Building => $to === LifecycleState::Shadow
                || $to === LifecycleState::Retired,
            LifecycleState::Shadow => $to === LifecycleState::Retired,
            LifecycleState::Verified => $to === LifecycleState::Retired,
            LifecycleState::Active => $to === LifecycleState::Retired,
            LifecycleState::Retired => false,
        };
    }
}
