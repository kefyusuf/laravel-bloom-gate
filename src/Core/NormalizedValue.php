<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class NormalizedValue
{
    private function __construct(
        private string $bytes,
    ) {}

    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function equals(self $other): bool
    {
        return $this->bytes === $other->bytes;
    }
}
