<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class AuthoritativeSetIdentity
{
    private const int MAX_BYTES = 256;

    private const string PATTERN = '/\A[\x21-\x7E]+\z/';

    private function __construct(
        private string $value,
    ) {}

    public static function fromString(string $value): self
    {
        $length = strlen($value);

        if ($length < 1 || $length > self::MAX_BYTES || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                'Authoritative-set identity must contain 1 to 256 visible ASCII bytes without whitespace or control characters.',
            );
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
