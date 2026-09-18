<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;

it('matches the sha256 double-hash v1 golden vectors', function (string $bytes, array $expected): void {
    $layout = BloomLayout::create(1024, 7, ProbeAlgorithm::Sha256DoubleHashV1);
    $generator = new BloomProbeGenerator;

    expect($generator->generate(NormalizedValue::fromBytes($bytes), $layout)->values())
        ->toBe($expected);
})->with([
    'empty bytes' => ['', [966, 363, 784, 181, 602, 1023, 420]],
    'ascii bytes' => ['ABC-001', [52, 476, 900, 300, 724, 124, 548]],
    'utf8 bytes' => ['ürün-ç', [336, 51, 790, 505, 220, 959, 674]],
    'embedded nul' => ["abc\0def", [218, 933, 624, 315, 6, 721, 412]],
]);

it('uses the defined single-bit special case', function (): void {
    $layout = BloomLayout::create(1, 1, ProbeAlgorithm::Sha256DoubleHashV1);

    expect((new BloomProbeGenerator)
        ->generate(NormalizedValue::fromBytes('ABC-001'), $layout)
        ->values())->toBe([0]);
});

it('keeps every generated position inside the maximum v1 layout without overflowing', function (): void {
    $maximum = 2_147_483_647;
    $layout = BloomLayout::create($maximum, 64, ProbeAlgorithm::Sha256DoubleHashV1);
    $positions = (new BloomProbeGenerator)
        ->generate(NormalizedValue::fromBytes('overflow-safety'), $layout)
        ->values();

    expect($positions)->toHaveCount(64);

    foreach ($positions as $position) {
        expect($position)->toBeGreaterThanOrEqual(0)
            ->and($position)->toBeLessThan($maximum);
    }
});
