<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\ProbeAlgorithm;

it('exposes the stable v1 probe algorithm identifier', function (): void {
    expect(ProbeAlgorithm::Sha256DoubleHashV1->value)->toBe('sha256-double-hash-v1');
});

it('defines exactly the supported probe algorithms', function (): void {
    expect(array_map(
        static fn (ProbeAlgorithm $case): string => $case->name,
        ProbeAlgorithm::cases(),
    ))->toBe([
        'Sha256DoubleHashV1',
    ]);
});
