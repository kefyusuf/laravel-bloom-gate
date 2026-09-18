<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final class BloomProbeGenerator
{
    private const SHA256_DOUBLE_HASH_V1_DOMAIN = "laravel-bloom-gate\0probe\0v1\0";

    public function generate(NormalizedValue $value, BloomLayout $layout): BitPositions
    {
        if ($layout->bitCount() === 1) {
            return BitPositions::forLayout($layout, [0]);
        }

        [$h1, $h2] = $this->sha256DoubleHashV1Seeds($value);

        $bitCount = $layout->bitCount();
        $start = $h1 % $bitCount;
        $step = 1 + ($h2 % ($bitCount - 1));

        $positions = [];
        $position = $start;

        for ($index = 0; $index < $layout->hashCount(); $index++) {
            $positions[] = $position;

            if ($index + 1 < $layout->hashCount()) {
                $position = $this->addModuloWithoutOverflow($position, $step, $bitCount);
            }
        }

        return BitPositions::forLayout($layout, $positions);
    }

    /**
     * @return array{int, int}
     */
    private function sha256DoubleHashV1Seeds(NormalizedValue $value): array
    {
        $digest = hash(
            'sha256',
            self::SHA256_DOUBLE_HASH_V1_DOMAIN.$value->bytes(),
            true,
        );

        return [
            $this->readPositive31BitBigEndian($digest, 0),
            $this->readPositive31BitBigEndian($digest, 4),
        ];
    }

    private function readPositive31BitBigEndian(string $bytes, int $offset): int
    {
        return ((ord($bytes[$offset]) & 0x7f) << 24)
            | (ord($bytes[$offset + 1]) << 16)
            | (ord($bytes[$offset + 2]) << 8)
            | ord($bytes[$offset + 3]);
    }

    private function addModuloWithoutOverflow(int $position, int $step, int $modulus): int
    {
        $wrapThreshold = $modulus - $step;

        if ($position >= $wrapThreshold) {
            return $position - $wrapThreshold;
        }

        return $position + $step;
    }
}
