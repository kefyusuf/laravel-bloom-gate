<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class GenerationControlState
{
    public function __construct(
        private FilterVersion $version,
        private LifecycleState $lifecycle,
        private HealthState $health,
    ) {}

    public function version(): FilterVersion
    {
        return $this->version;
    }

    public function lifecycle(): LifecycleState
    {
        return $this->lifecycle;
    }

    public function health(): HealthState
    {
        return $this->health;
    }
}
