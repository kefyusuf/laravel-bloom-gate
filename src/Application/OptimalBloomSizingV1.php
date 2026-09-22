<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;

final class OptimalBloomSizingV1
{
    public function layout(
        int $capacity,
        float $falsePositiveRate,
    ): BloomLayout {
        $this->assertInputs($capacity, $falsePositiveRate);

        $idealHashCount = -log($falsePositiveRate) / log(2.0);
        $hashCount = max(
            1,
            (int) round($idealHashCount, 0, PHP_ROUND_HALF_UP),
        );

        $rootProbability = $falsePositiveRate ** (1.0 / $hashCount);
        $denominator = log1p(-$rootProbability);
        $requiredBits = -(($hashCount * (float) $capacity) / $denominator);

        if (! is_finite($requiredBits) || $requiredBits > PHP_INT_MAX) {
            throw new BloomSizingUnsupported(
                'Bloom sizing exceeds the integer range supported by this runtime.',
            );
        }

        $bitCount = (int) ceil($requiredBits);

        while ($this->estimatedFalsePositiveRate(
            $capacity,
            $bitCount,
            $hashCount,
        ) > $falsePositiveRate) {
            if ($bitCount === PHP_INT_MAX) {
                throw new BloomSizingUnsupported(
                    'Bloom sizing cannot satisfy the requested false-positive rate within the integer range supported by this runtime.',
                );
            }

            $bitCount++;
        }

        try {
            return BloomLayout::create(
                bitCount: $bitCount,
                hashCount: $hashCount,
                probeAlgorithm: ProbeAlgorithm::Sha256DoubleHashV1,
            );
        } catch (InvalidArgumentException $exception) {
            throw new BloomSizingUnsupported(
                'Bloom sizing exceeds the selected probe protocol limits.',
                previous: $exception,
            );
        }
    }

    private function assertInputs(
        int $capacity,
        float $falsePositiveRate,
    ): void {
        if ($capacity < 1) {
            throw new InvalidArgumentException(
                'Bloom sizing capacity must be at least 1.',
            );
        }

        if (
            ! is_finite($falsePositiveRate)
            || $falsePositiveRate <= 0.0
            || $falsePositiveRate >= 1.0
        ) {
            throw new InvalidArgumentException(
                'Bloom sizing false-positive rate must be finite and greater than 0 and less than 1.',
            );
        }
    }

    private function estimatedFalsePositiveRate(
        int $capacity,
        int $bitCount,
        int $hashCount,
    ): float {
        $ratio = ($hashCount * (float) $capacity) / $bitCount;

        return (1.0 - exp(-$ratio)) ** $hashCount;
    }
}
