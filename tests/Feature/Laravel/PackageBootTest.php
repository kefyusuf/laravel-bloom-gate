<?php

declare(strict_types=1);

it('boots with safe default configuration', function (): void {
    expect(config('bloom-gate.enabled'))->toBeTrue()
        ->and(config('bloom-gate.default'))->toBe('redis')
        ->and(config('bloom-gate.filters'))->toBe([]);
});
