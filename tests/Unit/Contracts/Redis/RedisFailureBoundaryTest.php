<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use RuntimeException;

it('keeps redis command failures separate from bloom driver failures', function (): void {
    $redisFailure = new RedisCommandFailed('Redis EVAL failed.');
    $driverFailure = new BloomDriverOperationFailed('Bloom driver operation failed.');

    expect($redisFailure)->toBeInstanceOf(RuntimeException::class)
        ->and($redisFailure)->not->toBeInstanceOf(BloomDriverOperationFailed::class)
        ->and($driverFailure)->toBeInstanceOf(RuntimeException::class)
        ->and($driverFailure)->not->toBeInstanceOf(RedisCommandFailed::class);
});

it('represents storage corruption as a distinct bloom contract failure', function (): void {
    $corruption = new BloomStorageCorrupt('Bloom storage is corrupt.');

    expect($corruption)->toBeInstanceOf(RuntimeException::class)
        ->and($corruption)->not->toBeInstanceOf(BloomDriverOperationFailed::class);
});
