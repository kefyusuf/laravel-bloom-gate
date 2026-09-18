<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterVersion;

it('accepts positive filter versions', function (int $value): void {
    expect(FilterVersion::fromInt($value)->value())->toBe($value);
})->with([
    'first generation' => 1,
    'arbitrary generation' => 42,
    'maximum generation' => PHP_INT_MAX,
]);

it('rejects zero and negative filter versions', function (int $value): void {
    FilterVersion::fromInt($value);
})->with([
    'zero' => 0,
    'negative' => -1,
])->throws(InvalidArgumentException::class);

it('creates the next immutable generation', function (): void {
    $current = FilterVersion::fromInt(41);
    $next = $current->next();

    expect($current->value())->toBe(41)
        ->and($next->value())->toBe(42)
        ->and($current->equals($next))->toBeFalse();
});

it('compares filter versions by value', function (): void {
    expect(FilterVersion::fromInt(7)->equals(FilterVersion::fromInt(7)))->toBeTrue()
        ->and(FilterVersion::fromInt(7)->equals(FilterVersion::fromInt(8)))->toBeFalse();
});

it('rejects advancing beyond the maximum integer', function (): void {
    FilterVersion::fromInt(PHP_INT_MAX)->next();
})->throws(OverflowException::class);
