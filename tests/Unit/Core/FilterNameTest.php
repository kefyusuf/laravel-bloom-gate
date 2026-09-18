<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterName;

it('accepts valid filter names', function (string $value): void {
    $name = FilterName::fromString($value);

    expect($name->value())->toBe($value);
})->with([
    'single byte' => 'a',
    'two bytes' => 'A1',
    'dot namespace' => 'products.sku',
    'underscore' => 'users_email',
    'hyphen and dot' => 'tenant-42.orders.external_id',
    'maximum length' => str_repeat('a', 128),
]);

it('rejects invalid filter names', function (string $value): void {
    FilterName::fromString($value);
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 129),
    'leading dot' => '.products',
    'trailing dot' => 'products.',
    'leading underscore' => '_products',
    'trailing hyphen' => 'products-',
    'colon' => 'products:sku',
    'slash' => 'products/sku',
    'opening brace' => 'products{sku',
    'closing brace' => 'products}sku',
    'space' => 'products sku',
    'unicode' => 'ürün.sku',
])->throws(InvalidArgumentException::class);

it('preserves case as part of identity', function (): void {
    $lower = FilterName::fromString('products.sku');
    $mixed = FilterName::fromString('Products.Sku');

    expect($lower->value())->toBe('products.sku')
        ->and($mixed->value())->toBe('Products.Sku')
        ->and($lower->equals($mixed))->toBeFalse();
});

it('compares equal filter names by value', function (): void {
    expect(
        FilterName::fromString('products.sku')
            ->equals(FilterName::fromString('products.sku')),
    )->toBeTrue();
});
