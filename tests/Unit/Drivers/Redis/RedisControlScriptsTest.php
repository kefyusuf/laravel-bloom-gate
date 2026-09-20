<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Drivers\Redis\RedisControlScripts;

function redisControlScriptMarker(string $script, string $needle): int
{
    $position = strpos($script, $needle);

    if ($position === false) {
        throw new RuntimeException(sprintf(
            'Expected Redis control script marker [%s].',
            $needle,
        ));
    }

    return $position;
}

function redisControlScriptLastMarker(string $script, string $needle): int
{
    $position = strrpos($script, $needle);

    if ($position === false) {
        throw new RuntimeException(sprintf(
            'Expected Redis control script marker [%s].',
            $needle,
        ));
    }

    return $position;
}

it('uses one control key and no ttl primitives in read and cas scripts', function (string $script): void {
    expect($script)->toContain('KEYS[1]');
    expect($script)->not->toContain('KEYS[2]');
    expect($script)->not->toContain('EXPIRE');
    expect($script)->not->toContain('PEXPIRE');
    expect($script)->not->toContain('SETEX');
    expect($script)->not->toContain('PSETEX');
})->with([
    'read' => fn (): string => RedisControlScripts::read(),
    'compare and swap' => fn (): string => RedisControlScripts::compareAndSwap(),
]);

it('pins the private structured status tokens inside the scripts', function (): void {
    expect(RedisControlScripts::read())
        ->toContain("return {'100'}")
        ->toContain("return {'201'}");

    expect(RedisControlScripts::compareAndSwap())
        ->toContain("return {'100'}")
        ->toContain("return {'200'}")
        ->toContain("return {'201'}");
});

it('validates read key type and strict hash shape before returning fields', function (): void {
    $script = RedisControlScripts::read();

    $typeCheck = redisControlScriptMarker($script, "redis.call('TYPE', KEYS[1]).ok");
    $hashRead = redisControlScriptMarker($script, "redis.call('HGETALL', KEYS[1])");
    $validation = redisControlScriptMarker(
        $script,
        'local valid, revision = validateControlFields(fields)',
    );
    $success = redisControlScriptMarker($script, "response[1] = '100'");

    expect($typeCheck)->toBeLessThan($hashRead);
    expect($hashRead)->toBeLessThan($validation);
    expect($validation)->toBeLessThan($success);
});

it('orders cas validation before every mutation', function (): void {
    $script = RedisControlScripts::compareAndSwap();

    $typeCheck = redisControlScriptMarker($script, "redis.call('TYPE', KEYS[1]).ok");
    $existingRead = redisControlScriptMarker($script, "redis.call('HGETALL', KEYS[1])");
    $existingValidation = redisControlScriptMarker(
        $script,
        'local currentValid, currentRevision = validateControlFields(currentFields)',
    );
    $revisionCheck = redisControlScriptMarker(
        $script,
        'if currentRevision ~= expectedRevision then',
    );
    $nextValidation = redisControlScriptMarker(
        $script,
        'local nextValid, nextRevision = validateControlFields(nextFields)',
    );
    $delete = redisControlScriptMarker($script, "redis.call('DEL', KEYS[1])");
    $write = redisControlScriptMarker(
        $script,
        "redis.call('HSET', KEYS[1], unpack(nextFields))",
    );

    expect($typeCheck)->toBeLessThan($existingRead);
    expect($existingRead)->toBeLessThan($existingValidation);
    expect($existingValidation)->toBeLessThan($revisionCheck);
    expect($revisionCheck)->toBeLessThan($nextValidation);
    expect($nextValidation)->toBeLessThan($delete);
    expect($delete)->toBeLessThan($write);
});

it('places every cas failure return before the first mutation', function (): void {
    $script = RedisControlScripts::compareAndSwap();
    $lastConflict = redisControlScriptLastMarker($script, "return {'200'}");
    $lastCorruption = redisControlScriptLastMarker($script, "return {'201'}");
    $delete = redisControlScriptMarker($script, "redis.call('DEL', KEYS[1])");

    expect($lastConflict)->toBeLessThan($delete);
    expect($lastCorruption)->toBeLessThan($delete);
});

it('keeps lifecycle transition policy out of redis control scripts', function (string $script): void {
    expect($script)->not->toContain('Configured');
    expect($script)->not->toContain('Building');
    expect($script)->not->toContain('Shadow');
    expect($script)->not->toContain('Verified');
    expect($script)->not->toContain('Active');
    expect($script)->not->toContain('Retired');
})->with([
    'read' => fn (): string => RedisControlScripts::read(),
    'compare and swap' => fn (): string => RedisControlScripts::compareAndSwap(),
]);
