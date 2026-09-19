<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterStateRevision;

it('accepts positive state revisions', function (int $value): void {
    expect(FilterStateRevision::fromInt($value)->value())->toBe($value);
})->with([
    'first revision' => 1,
    'arbitrary revision' => 42,
    'maximum revision' => PHP_INT_MAX,
]);

it('rejects zero and negative state revisions', function (int $value): void {
    FilterStateRevision::fromInt($value);
})->with([
    'zero' => 0,
    'negative' => -1,
])->throws(InvalidArgumentException::class);

it('compares state revisions by value', function (): void {
    expect(FilterStateRevision::fromInt(7)->equals(FilterStateRevision::fromInt(7)))->toBeTrue()
        ->and(FilterStateRevision::fromInt(7)->equals(FilterStateRevision::fromInt(8)))->toBeFalse();
});

it('creates the next immutable state revision', function (): void {
    $current = FilterStateRevision::fromInt(41);
    $next = $current->next();

    expect($current->value())->toBe(41)
        ->and($next->value())->toBe(42)
        ->and($current->equals($next))->toBeFalse();
});

it('rejects advancing beyond the maximum integer', function (): void {
    FilterStateRevision::fromInt(PHP_INT_MAX)->next();
})->throws(OverflowException::class);
