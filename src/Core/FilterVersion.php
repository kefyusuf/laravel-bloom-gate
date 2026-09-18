<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;
use OverflowException;

final readonly class FilterVersion
{
    private function __construct(
        private int $value,
    ) {}

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Filter version must be a positive integer starting at 1.');
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function next(): self
    {
        if ($this->value === PHP_INT_MAX) {
            throw new OverflowException('Filter version cannot advance beyond PHP_INT_MAX.');
        }

        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
