<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;

final class ActiveGenerationPolicy
{
    public function eligibleVersion(
        ?FilterControlState $state,
    ): ?FilterVersion {
        if ($state === null) {
            return null;
        }

        $activeVersion = $state->activeVersion();

        if ($activeVersion === null) {
            return null;
        }

        foreach ($state->generations() as $generation) {
            if ($generation->version()->equals($activeVersion) === false) {
                continue;
            }

            if (
                $generation->lifecycle() !== LifecycleState::Active
                || $generation->health() !== HealthState::Healthy
            ) {
                return null;
            }

            return $generation->version();
        }

        return null;
    }
}
