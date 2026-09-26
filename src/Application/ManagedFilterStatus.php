<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;

final readonly class ManagedFilterStatus
{
    public function __construct(
        private FilterName $name,
        private bool $registered,
        private bool $globalQueryOptimizationEnabled,
        private ?bool $queryOptimizationEnabled,
        private ?ConsistencyContract $consistency,
        private ?ManagedGenerationStatus $active,
        private ?ManagedGenerationStatus $candidate,
    ) {}

    public function name(): FilterName
    {
        return $this->name;
    }

    public function registered(): bool
    {
        return $this->registered;
    }

    public function globalQueryOptimizationEnabled(): bool
    {
        return $this->globalQueryOptimizationEnabled;
    }

    public function queryOptimizationEnabled(): ?bool
    {
        return $this->queryOptimizationEnabled;
    }

    public function consistency(): ?ConsistencyContract
    {
        return $this->consistency;
    }

    public function active(): ?ManagedGenerationStatus
    {
        return $this->active;
    }

    public function candidate(): ?ManagedGenerationStatus
    {
        return $this->candidate;
    }
}
