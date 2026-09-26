<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Application\BloomSizingUnsupported;
use Kefyusuf\BloomGate\Application\OptimalBloomSizingV1;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;

function task3EstimatedFalsePositiveRate(
    BloomLayout $layout,
    int $capacity,
): float {
    $ratio = ($layout->hashCount() * $capacity) / $layout->bitCount();

    return (1.0 - exp(-$ratio)) ** $layout->hashCount();
}

it('pins the canonical one-million at one-per-thousand sizing vector', function (): void {
    $layout = (new OptimalBloomSizingV1)->layout(
        capacity: 1_000_000,
        falsePositiveRate: 0.001,
    );

    expect($layout->bitCount())->toBe(14_377_640)
        ->and($layout->hashCount())->toBe(10)
        ->and($layout->probeAlgorithm())->toBe(ProbeAlgorithm::Sha256DoubleHashV1)
        ->and(task3EstimatedFalsePositiveRate($layout, 1_000_000))
        ->toBeLessThanOrEqual(0.001);
});

it('matches deterministic managed sizing vectors', function (
    int $capacity,
    float $falsePositiveRate,
    int $expectedBitCount,
    int $expectedHashCount,
): void {
    $layout = (new OptimalBloomSizingV1)->layout(
        capacity: $capacity,
        falsePositiveRate: $falsePositiveRate,
    );

    expect($layout->bitCount())->toBe($expectedBitCount)
        ->and($layout->hashCount())->toBe($expectedHashCount)
        ->and($layout->probeAlgorithm())->toBe(ProbeAlgorithm::Sha256DoubleHashV1)
        ->and(task3EstimatedFalsePositiveRate($layout, $capacity))
        ->toBeLessThanOrEqual($falsePositiveRate);
})->with([
    'one percent' => [1_000, 0.01, 9_593, 7],
    'ten percent' => [1_000, 0.1, 4_809, 3],
    'high fpr floors hash count at one' => [1_000, 0.9, 435, 1],
    'single member high fpr remains a valid layout' => [1, 0.9, 1, 1],
]);

it('uses explicit half-up rounding for the ideal hash-count tie', function (): void {
    $falsePositiveRate = 2.0 ** -1.5;

    $layout = (new OptimalBloomSizingV1)->layout(
        capacity: 1_000,
        falsePositiveRate: $falsePositiveRate,
    );

    expect($layout->hashCount())->toBe(2)
        ->and($layout->bitCount())->toBe(2_216)
        ->and(task3EstimatedFalsePositiveRate($layout, 1_000))
        ->toBeLessThanOrEqual($falsePositiveRate);
});

it('rejects non-positive capacities', function (int $capacity): void {
    (new OptimalBloomSizingV1)->layout(
        capacity: $capacity,
        falsePositiveRate: 0.01,
    );
})->with([
    'zero' => 0,
    'negative' => -1,
])->throws(InvalidArgumentException::class);

it('rejects non-finite or out-of-range false-positive rates', function (
    float $falsePositiveRate,
): void {
    (new OptimalBloomSizingV1)->layout(
        capacity: 1_000,
        falsePositiveRate: $falsePositiveRate,
    );
})->with([
    'zero' => 0.0,
    'one' => 1.0,
    'negative' => -0.1,
    'greater than one' => 1.1,
    'nan' => NAN,
    'positive infinity' => INF,
    'negative infinity' => -INF,
])->throws(InvalidArgumentException::class);

it('rejects sizing that requires more than the m2 hash-count limit', function (): void {
    (new OptimalBloomSizingV1)->layout(
        capacity: 1_000,
        falsePositiveRate: 1.0e-20,
    );
})->throws(BloomSizingUnsupported::class);

it('rejects sizing that requires more than the m2 bit-count limit', function (): void {
    (new OptimalBloomSizingV1)->layout(
        capacity: 150_000_000,
        falsePositiveRate: 0.001,
    );
})->throws(BloomSizingUnsupported::class);

it('does not silently clamp unsupported sizing requests', function (): void {
    $sizing = new ReflectionClass(OptimalBloomSizingV1::class);

    expect($sizing->isFinal())->toBeTrue()
        ->and($sizing->getConstructor())->toBeNull();

    $method = $sizing->getMethod('layout');
    $parameters = $method->getParameters();

    $returnType = $method->getReturnType();

    if (! $returnType instanceof ReflectionNamedType) {
        throw new RuntimeException('Expected OptimalBloomSizingV1::layout to use a named return type.');
    }

    expect($parameters)->toHaveCount(2)
        ->and($parameters[0]->getName())->toBe('capacity')
        ->and($parameters[1]->getName())->toBe('falsePositiveRate')
        ->and($returnType->getName())->toBe(BloomLayout::class);
});
