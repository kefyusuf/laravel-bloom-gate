<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class BloomLayout
{
    private const MAX_HASH_COUNT = 64;

    private const SHA256_DOUBLE_HASH_V1_MAX_BIT_COUNT = 2_147_483_647;

    private function __construct(
        private int $bitCount,
        private int $hashCount,
        private ProbeAlgorithm $probeAlgorithm,
    ) {}

    public static function create(
        int $bitCount,
        int $hashCount,
        ProbeAlgorithm $probeAlgorithm,
    ): self {
        if ($bitCount < 1) {
            throw new InvalidArgumentException('Bloom layout bit count must be at least 1.');
        }

        if ($hashCount < 1) {
            throw new InvalidArgumentException('Bloom layout hash count must be at least 1.');
        }

        if ($hashCount > $bitCount) {
            throw new InvalidArgumentException('Bloom layout hash count cannot exceed the bit count.');
        }

        if ($hashCount > self::MAX_HASH_COUNT) {
            throw new InvalidArgumentException('Bloom layout hash count cannot exceed 64.');
        }

        if ($bitCount > self::maxBitCountFor($probeAlgorithm)) {
            throw new InvalidArgumentException('Bloom layout bit count exceeds the selected probe algorithm limit.');
        }

        return new self($bitCount, $hashCount, $probeAlgorithm);
    }

    public function bitCount(): int
    {
        return $this->bitCount;
    }

    public function hashCount(): int
    {
        return $this->hashCount;
    }

    public function probeAlgorithm(): ProbeAlgorithm
    {
        return $this->probeAlgorithm;
    }

    public function equals(self $other): bool
    {
        return $this->bitCount === $other->bitCount
            && $this->hashCount === $other->hashCount
            && $this->probeAlgorithm === $other->probeAlgorithm;
    }

    private static function maxBitCountFor(ProbeAlgorithm $probeAlgorithm): int
    {
        return match ($probeAlgorithm) {
            ProbeAlgorithm::Sha256DoubleHashV1 => self::SHA256_DOUBLE_HASH_V1_MAX_BIT_COUNT,
        };
    }
}
