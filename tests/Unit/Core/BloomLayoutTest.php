<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;

it('retains a valid bloom layout exactly', function (): void {
    $layout = BloomLayout::create(1024, 7, ProbeAlgorithm::Sha256DoubleHashV1);

    expect($layout->bitCount())->toBe(1024)
        ->and($layout->hashCount())->toBe(7)
        ->and($layout->probeAlgorithm())->toBe(ProbeAlgorithm::Sha256DoubleHashV1);
});

it('accepts the minimum valid layout', function (): void {
    $layout = BloomLayout::create(1, 1, ProbeAlgorithm::Sha256DoubleHashV1);

    expect($layout->bitCount())->toBe(1)
        ->and($layout->hashCount())->toBe(1);
});

it('rejects invalid layout parameters', function (int $bitCount, int $hashCount): void {
    BloomLayout::create($bitCount, $hashCount, ProbeAlgorithm::Sha256DoubleHashV1);
})->with([
    'zero bits' => [0, 1],
    'zero hashes' => [1024, 0],
    'hashes exceed bits' => [4, 5],
    'more than 64 hashes' => [1024, 65],
])->throws(InvalidArgumentException::class);

it('compares bloom layouts by all protocol parameters', function (): void {
    $layout = BloomLayout::create(1024, 7, ProbeAlgorithm::Sha256DoubleHashV1);

    expect($layout->equals(BloomLayout::create(1024, 7, ProbeAlgorithm::Sha256DoubleHashV1)))->toBeTrue()
        ->and($layout->equals(BloomLayout::create(2048, 7, ProbeAlgorithm::Sha256DoubleHashV1)))->toBeFalse()
        ->and($layout->equals(BloomLayout::create(1024, 6, ProbeAlgorithm::Sha256DoubleHashV1)))->toBeFalse();
});
