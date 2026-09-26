<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class ProductionFilterRuntimeStatus
{
    private function __construct(
        private ?LifecycleState $activeLifecycle,
        private ?HealthState $activeHealth,
        private bool $activeLayoutAvailable,
        private bool $activeSemanticBound,
        private ?bool $activeSemanticMatches,
    ) {
        if (($this->activeLifecycle === null) !== ($this->activeHealth === null)) {
            throw new InvalidArgumentException(
                'Production filter active lifecycle and health must be present together.',
            );
        }

        if (
            $this->activeLifecycle === null
            && (
                $this->activeLayoutAvailable
                || $this->activeSemanticBound
                || $this->activeSemanticMatches !== null
            )
        ) {
            throw new InvalidArgumentException(
                'Production filter without an active generation cannot expose active storage or semantic state.',
            );
        }

        if (
            $this->activeSemanticBound === false
            && $this->activeSemanticMatches !== null
        ) {
            throw new InvalidArgumentException(
                'Unbound active semantics cannot expose a match result.',
            );
        }
    }

    public static function withoutActiveGeneration(): self
    {
        return new self(
            activeLifecycle: null,
            activeHealth: null,
            activeLayoutAvailable: false,
            activeSemanticBound: false,
            activeSemanticMatches: null,
        );
    }

    public static function active(
        LifecycleState $lifecycle,
        HealthState $health,
        bool $layoutAvailable,
        bool $semanticBound,
        ?bool $semanticMatches,
    ): self {
        return new self(
            activeLifecycle: $lifecycle,
            activeHealth: $health,
            activeLayoutAvailable: $layoutAvailable,
            activeSemanticBound: $semanticBound,
            activeSemanticMatches: $semanticMatches,
        );
    }

    public function hasActiveGeneration(): bool
    {
        return $this->activeLifecycle !== null;
    }

    public function activeLifecycle(): ?LifecycleState
    {
        return $this->activeLifecycle;
    }

    public function activeHealth(): ?HealthState
    {
        return $this->activeHealth;
    }

    public function activeLayoutAvailable(): bool
    {
        return $this->activeLayoutAvailable;
    }

    public function activeSemanticBound(): bool
    {
        return $this->activeSemanticBound;
    }

    public function activeSemanticMatches(): ?bool
    {
        return $this->activeSemanticMatches;
    }
}
