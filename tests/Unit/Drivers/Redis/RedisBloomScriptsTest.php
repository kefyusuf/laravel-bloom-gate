<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomScripts;

it('pins the internal redis script protocol constants', function (): void {
    expect(RedisBloomScripts::STORAGE_FORMAT)->toBe('redis-bitmap-v1')
        ->and(RedisBloomScripts::STATUS_OK)->toBe(100)
        ->and(RedisBloomScripts::STATUS_MEMBERSHIP_ABSENT)->toBe(101)
        ->and(RedisBloomScripts::STATUS_MEMBERSHIP_MAYBE_PRESENT)->toBe(102)
        ->and(RedisBloomScripts::STATUS_NOT_PROVISIONED)->toBe(200)
        ->and(RedisBloomScripts::STATUS_LAYOUT_CONFLICT)->toBe(201)
        ->and(RedisBloomScripts::STATUS_LAYOUT_MISMATCH)->toBe(202)
        ->and(RedisBloomScripts::STATUS_STORAGE_CORRUPT)->toBe(203)
        ->and(RedisBloomScripts::META_FORMAT)->toBe('format')
        ->and(RedisBloomScripts::META_BIT_COUNT)->toBe('bit_count')
        ->and(RedisBloomScripts::META_HASH_COUNT)->toBe('hash_count')
        ->and(RedisBloomScripts::META_PROBE_ALGORITHM)->toBe('probe_algorithm');
});

it('keeps every script on the two-key generation protocol', function (string $script): void {
    expect($script)->toContain('KEYS[1]')
        ->and($script)->toContain('KEYS[2]');
})->with([
    'provision' => fn (): string => RedisBloomScripts::provision(),
    'add' => fn (): string => RedisBloomScripts::add(),
    'might contain' => fn (): string => RedisBloomScripts::mightContain(),
    'destroy' => fn (): string => RedisBloomScripts::destroy(),
]);

it('pins the common metadata argv protocol for stateful scripts', function (string $script): void {
    expect($script)->toContain('ARGV[1]')
        ->and($script)->toContain('ARGV[2]')
        ->and($script)->toContain('ARGV[3]')
        ->and($script)->toContain('ARGV[4]')
        ->and($script)->toContain("'format'")
        ->and($script)->toContain("'bit_count'")
        ->and($script)->toContain("'hash_count'")
        ->and($script)->toContain("'probe_algorithm'");
})->with([
    'provision' => fn (): string => RedisBloomScripts::provision(),
    'add' => fn (): string => RedisBloomScripts::add(),
    'might contain' => fn (): string => RedisBloomScripts::mightContain(),
]);

it('pins the redis primitive used by each script', function (string $script, string $primitive): void {
    expect($script)->toContain($primitive);
})->with([
    'provision writes metadata' => [
        fn (): string => RedisBloomScripts::provision(),
        "redis.call('HSET'",
    ],
    'add sets bitmap positions' => [
        fn (): string => RedisBloomScripts::add(),
        "redis.call('SETBIT'",
    ],
    'might contain reads bitmap positions' => [
        fn (): string => RedisBloomScripts::mightContain(),
        "redis.call('GETBIT'",
    ],
    'destroy removes both generation keys' => [
        fn (): string => RedisBloomScripts::destroy(),
        "redis.call('DEL', KEYS[1], KEYS[2])",
    ],
]);

it('validates state before bitmap mutation in the add script', function (): void {
    $script = RedisBloomScripts::add();

    $validation = strpos($script, "if metadataMatches == false then");
    $mutation = strpos($script, "redis.call('SETBIT'");

    expect($validation)->not->toBeFalse()
        ->and($mutation)->not->toBeFalse()
        ->and($validation)->toBeLessThan($mutation);
});
