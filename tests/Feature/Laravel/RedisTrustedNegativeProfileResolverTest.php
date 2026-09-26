<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Laravel\Redis\RedisTrustedNegativeProfileResolver;

function task8RedisProfileConfig(): Repository
{
    $config = app()->make(Repository::class);

    return $config;
}

it('defaults redis trusted-negative profile to null', function (): void {
    expect(config('bloom-gate.drivers.redis.trusted_negative_profile'))
        ->toBeNull();

    $resolver = app()->make(RedisTrustedNegativeProfileResolver::class);

    expect($resolver->resolve())->toBeNull();
});

it('accepts only the locked m5 redis trusted-negative profile', function (): void {
    task8RedisProfileConfig()->set(
        'bloom-gate.drivers.redis.trusted_negative_profile',
        'standalone-primary-durable-v1',
    );

    $resolver = app()->make(RedisTrustedNegativeProfileResolver::class);

    expect($resolver->resolve())->toBe('standalone-primary-durable-v1');
});

it('rejects unknown or malformed redis trusted-negative profiles', function (
    mixed $profile,
): void {
    task8RedisProfileConfig()->set(
        'bloom-gate.drivers.redis.trusted_negative_profile',
        $profile,
    );

    $resolver = app()->make(RedisTrustedNegativeProfileResolver::class);

    expect(fn () => $resolver->resolve())
        ->toThrow(InvalidConfiguration::class);
})->with([
    'unknown' => 'standalone-primary-v2',
    'empty' => '',
    'boolean' => true,
    'array' => [['standalone-primary-durable-v1']],
]);
