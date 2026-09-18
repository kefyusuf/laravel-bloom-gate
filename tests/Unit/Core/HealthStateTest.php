<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\HealthState;

it('defines exactly the operational health states', function (): void {
    expect(array_map(
        static fn (HealthState $case): string => $case->name,
        HealthState::cases(),
    ))->toBe([
        'Healthy',
        'Degraded',
        'Stale',
        'Unavailable',
    ]);
});

it('is intentionally unbacked', function (): void {
    expect((new ReflectionEnum(HealthState::class))->isBacked())->toBeFalse();
});
