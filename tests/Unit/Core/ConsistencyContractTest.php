<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\ConsistencyContract;

it('exposes exactly the two locked m5 consistency contracts', function (): void {
    expect(array_map(
        static fn (ConsistencyContract $contract): string => $contract->value,
        ConsistencyContract::cases(),
    ))->toBe([
        'immutable-v1',
        'preadd-v1',
    ]);
});
