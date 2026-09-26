<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class ActiveGenerationSnapshot
{
    public function __construct(
        private FilterName $filterName,
        private FilterStateRevision $revision,
        private FilterVersion $activeVersion,
        private LifecycleState $lifecycle,
        private HealthState $health,
    ) {}

    public function filterName(): FilterName
    {
        return $this->filterName;
    }

    public function revision(): FilterStateRevision
    {
        return $this->revision;
    }

    public function activeVersion(): FilterVersion
    {
        return $this->activeVersion;
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
