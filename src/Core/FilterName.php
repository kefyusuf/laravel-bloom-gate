<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class FilterName
{
    private const MAX_BYTES = 128;

    private const PATTERN = '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,126}[A-Za-z0-9])?\z/';

    private function __construct(
        private string $value,
    ) {}

    public static function fromString(string $value): self
    {
        $length = strlen($value);

        if ($length < 1 || $length > self::MAX_BYTES || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                'Filter name must contain 1 to 128 ASCII bytes, begin and end with an alphanumeric character, and use only letters, digits, dot, underscore, or hyphen.',
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
