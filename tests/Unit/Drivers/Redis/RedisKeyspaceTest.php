<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;

it('builds canonical generation keys with a shared cluster hash tag', function (): void {
    $keyspace = RedisKeyspace::fromPrefix('lbg');
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(1);

    expect($keyspace->metaKey($name, $version))
        ->toBe('lbg:{products.sku}:v:1:meta')
        ->and($keyspace->bitmapKey($name, $version))
        ->toBe('lbg:{products.sku}:v:1:bf');
});

it('preserves exact case-sensitive filter identity', function (): void {
    $keyspace = RedisKeyspace::fromPrefix('lbg');
    $version = FilterVersion::fromInt(1);

    expect($keyspace->metaKey(FilterName::fromString('users.email'), $version))
        ->not->toBe($keyspace->metaKey(FilterName::fromString('Users.Email'), $version));
});

it('encodes filter versions as unpadded base-10 decimals', function (): void {
    $keyspace = RedisKeyspace::fromPrefix('lbg');

    expect($keyspace->bitmapKey(
        FilterName::fromString('products.sku'),
        FilterVersion::fromInt(42),
    ))->toBe('lbg:{products.sku}:v:42:bf');
});

it('accepts bounded ascii prefixes', function (string $prefix): void {
    $keyspace = RedisKeyspace::fromPrefix($prefix);

    expect($keyspace->metaKey(
        FilterName::fromString('products.sku'),
        FilterVersion::fromInt(1),
    ))->toStartWith($prefix.':{products.sku}:');
})->with([
    'single alphanumeric' => 'a',
    'mixed safe separators' => 'LBG_1.pkg-test',
    'maximum length' => str_repeat('a', 64),
]);

it('rejects unsafe redis key prefixes', function (string $prefix): void {
    RedisKeyspace::fromPrefix($prefix);
})->with([
    'empty' => '',
    'leading punctuation' => '.lbg',
    'colon' => 'lbg:tenant',
    'opening brace' => 'lbg{tenant',
    'closing brace' => 'lbg}tenant',
    'space' => 'lbg tenant',
    'unicode' => 'ürün',
    'too long' => str_repeat('a', 65),
])->throws(InvalidArgumentException::class);

it('builds the canonical control state key with the same logical filter hash tag', function (): void {
    $keyspace = RedisKeyspace::fromPrefix('lbg');
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(9);

    expect($keyspace->stateKey($name))
        ->toBe('lbg:{products.sku}:state')
        ->and($keyspace->stateStagingKey($name))
        ->toBe('lbg:{products.sku}:state:staging')
        ->and($keyspace->stateKey($name))
        ->toContain('{products.sku}')
        ->and($keyspace->stateStagingKey($name))
        ->toContain('{products.sku}')
        ->and($keyspace->metaKey($name, $version))
        ->toContain('{products.sku}')
        ->and($keyspace->bitmapKey($name, $version))
        ->toContain('{products.sku}');
});

it('keeps existing generation key bytes unchanged when control state keys are introduced', function (): void {
    $keyspace = RedisKeyspace::fromPrefix('lbg');
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(42);

    expect($keyspace->metaKey($name, $version))
        ->toBe('lbg:{products.sku}:v:42:meta')
        ->and($keyspace->bitmapKey($name, $version))
        ->toBe('lbg:{products.sku}:v:42:bf');
});
