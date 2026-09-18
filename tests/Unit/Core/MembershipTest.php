<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\Membership;

it('defines exactly the membership semantics', function (): void {
    expect(array_map(
        static fn (Membership $case): string => $case->name,
        Membership::cases(),
    ))->toBe([
        'DefinitelyAbsent',
        'MaybePresent',
        'Bypassed',
    ]);
});
