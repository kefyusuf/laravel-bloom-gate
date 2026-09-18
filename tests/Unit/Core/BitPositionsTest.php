<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;

it('preserves ordered positions including duplicates', function (): void {
    $layout = BloomLayout::create(16, 4, ProbeAlgorithm::Sha256DoubleHashV1);
    $positions = BitPositions::forLayout($layout, [3, 7, 3, 15]);

    expect($positions->layout()->equals($layout))->toBeTrue()
        ->and($positions->values())->toBe([3, 7, 3, 15])
        ->and($positions->count())->toBe(4);
});

it('rejects a position count different from the layout hash count', function (): void {
    $layout = BloomLayout::create(16, 4, ProbeAlgorithm::Sha256DoubleHashV1);

    BitPositions::forLayout($layout, [1, 2, 3]);
})->throws(InvalidArgumentException::class);

it('rejects positions outside the layout bit range', function (array $values): void {
    $layout = BloomLayout::create(16, 2, ProbeAlgorithm::Sha256DoubleHashV1);

    BitPositions::forLayout($layout, $values);
})->with([
    'negative' => [[-1, 3]],
    'equal to bit count' => [[1, 16]],
])->throws(InvalidArgumentException::class);

it('compares positions by layout and ordered values', function (): void {
    $layout = BloomLayout::create(16, 4, ProbeAlgorithm::Sha256DoubleHashV1);
    $otherLayout = BloomLayout::create(32, 4, ProbeAlgorithm::Sha256DoubleHashV1);

    $positions = BitPositions::forLayout($layout, [1, 2, 3, 4]);

    expect($positions->equals(BitPositions::forLayout($layout, [1, 2, 3, 4])))->toBeTrue()
        ->and($positions->equals(BitPositions::forLayout($layout, [4, 3, 2, 1])))->toBeFalse()
        ->and($positions->equals(BitPositions::forLayout($otherLayout, [1, 2, 3, 4])))->toBeFalse();
});
