<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\NormalizedValue;

it('preserves exact byte sequences', function (string $bytes): void {
    $value = NormalizedValue::fromBytes($bytes);

    expect($value->bytes())->toBe($bytes)
        ->and($value->length())->toBe(strlen($bytes));
})->with([
    'empty' => '',
    'ascii' => 'ABC-001',
    'surrounding whitespace' => '  ABC-001  ',
    'utf8 bytes' => 'ürün-ç',
    'embedded nul' => "abc\0def",
]);

it('compares normalized values by exact bytes', function (): void {
    expect(NormalizedValue::fromBytes('ABC')
        ->equals(NormalizedValue::fromBytes('ABC')))->toBeTrue()
        ->and(NormalizedValue::fromBytes('ABC')
            ->equals(NormalizedValue::fromBytes('abc')))->toBeFalse()
        ->and(NormalizedValue::fromBytes('ABC')
            ->equals(NormalizedValue::fromBytes(' ABC ')))->toBeFalse();
});

it('uses byte length rather than character count', function (): void {
    $bytes = 'ürün';

    expect(NormalizedValue::fromBytes($bytes)->length())->toBe(strlen($bytes))
        ->and(strlen($bytes))->toBeGreaterThan(4);
});
