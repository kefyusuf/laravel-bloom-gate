<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\FilterName;

final readonly class RegisteredFilter
{
    public function __construct(
        private FilterName $name,
        private FilterDefinition $definition,
        private bool $queryOptimizationEnabled,
        private int $capacity,
        private float $falsePositiveRate,
    ) {
        if ($this->capacity < 1) {
            throw new InvalidArgumentException(
                'Registered filter capacity must be at least 1.',
            );
        }

        if (
            ! is_finite($this->falsePositiveRate)
            || $this->falsePositiveRate <= 0.0
            || $this->falsePositiveRate >= 1.0
        ) {
            throw new InvalidArgumentException(
                'Registered filter false-positive rate must be finite and greater than 0 and less than 1.',
            );
        }
    }

    public function name(): FilterName
    {
        return $this->name;
    }

    public function definition(): FilterDefinition
    {
        return $this->definition;
    }

    public function queryOptimizationEnabled(): bool
    {
        return $this->queryOptimizationEnabled;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function falsePositiveRate(): float
    {
        return $this->falsePositiveRate;
    }
}
