<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\LifecycleState;

it('defines exactly the lifecycle states', function (): void {
    expect(array_map(
        static fn (LifecycleState $case): string => $case->name,
        LifecycleState::cases(),
    ))->toBe([
        'Configured',
        'Building',
        'Shadow',
        'Verified',
        'Active',
        'Retired',
    ]);
});
