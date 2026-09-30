<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class WriterLeaseToken
{
    private function __construct(
        private string $value,
    ) {}

    public static function generate(): self
    {
        return self::fromString(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Writer lease token must be exactly 32 lowercase hexadecimal characters.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
