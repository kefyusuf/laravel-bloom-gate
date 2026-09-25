<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;

final readonly class ManagedGenerationStatus
{
    public function __construct(
        private string $slot,
        private FilterVersion $version,
        private LifecycleState $lifecycle,
        private HealthState $health,
        private ?BloomLayout $layout,
        private bool $semanticBound,
        private ?bool $semanticMatches,
    ) {
        if ($this->slot !== 'active' && $this->slot !== 'candidate') {
            throw new InvalidArgumentException(
                'Managed generation status slot must be active or candidate.',
            );
        }

        if ($this->semanticBound === false && $this->semanticMatches !== null) {
            throw new InvalidArgumentException(
                'Unbound generation semantics cannot report a match result.',
            );
        }
    }

    public function slot(): string
    {
        return $this->slot;
    }

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

    public function layout(): ?BloomLayout
    {
        return $this->layout;
    }

    public function semanticBound(): bool
    {
        return $this->semanticBound;
    }

    public function semanticMatches(): ?bool
    {
        return $this->semanticMatches;
    }
}
