<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class NormalizationFingerprint
{
    private const string PATTERN = '/\Asha256:[0-9a-f]{64}\z/';

    private function __construct(
        private string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                'Normalization fingerprint must use canonical sha256:<64 lowercase hex> encoding.',
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
