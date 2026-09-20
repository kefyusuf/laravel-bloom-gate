<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Drivers\Redis\RedisControlScripts;

it('uses one control key and no ttl primitives in read and cas scripts', function (string $script): void {
    expect($script)->toContain('KEYS[1]')
        ->not->toContain('KEYS[2]')
        ->not->toContain('EXPIRE')
        ->not->toContain('PEXPIRE')
        ->not->toContain('SETEX')
        ->not->toContain('PSETEX');
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

    $typeCheck = strpos($script, "redis.call('TYPE', KEYS[1]).ok");
    $hashRead = strpos($script, "redis.call('HGETALL', KEYS[1])");
    $validation = strpos($script, 'local valid, revision = validateControlFields(fields)');
    $success = strpos($script, "response[1] = '100'");

    if (
        $typeCheck === false
        || $hashRead === false
        || $validation === false
        || $success === false
    ) {
        throw new RuntimeException('Expected read control script protocol markers.');
    }

    expect($typeCheck)->toBeLessThan($hashRead)
        ->and($hashRead)->toBeLessThan($validation)
        ->and($validation)->toBeLessThan($success);
});

it('orders cas validation before every mutation', function (): void {
    $script = RedisControlScripts::compareAndSwap();

    $typeCheck = strpos($script, "redis.call('TYPE', KEYS[1]).ok");
    $existingRead = strpos($script, "redis.call('HGETALL', KEYS[1])");
    $existingValidation = strpos($script, 'local currentValid, currentRevision = validateControlFields(currentFields)');
    $revisionCheck = strpos($script, 'if currentRevision ~= expectedRevision then');
    $nextValidation = strpos($script, 'local nextValid, nextRevision = validateControlFields(nextFields)');
    $delete = strpos($script, "redis.call('DEL', KEYS[1])");
    $write = strpos($script, "redis.call('HSET', KEYS[1], unpack(nextFields))");

    foreach ([
        $typeCheck,
        $existingRead,
        $existingValidation,
        $revisionCheck,
        $nextValidation,
        $delete,
        $write,
    ] as $marker) {
        if ($marker === false) {
            throw new RuntimeException('Expected CAS control script protocol marker.');
        }
    }

    expect($typeCheck)->toBeLessThan($existingRead)
        ->and($existingRead)->toBeLessThan($existingValidation)
        ->and($existingValidation)->toBeLessThan($revisionCheck)
        ->and($revisionCheck)->toBeLessThan($nextValidation)
        ->and($nextValidation)->toBeLessThan($delete)
        ->and($delete)->toBeLessThan($write);
});

it('keeps lifecycle transition policy out of redis control scripts', function (string $script): void {
    expect($script)->not->toContain('Configured')
        ->not->toContain('Building')
        ->not->toContain('Shadow')
        ->not->toContain('Verified')
        ->not->toContain('Active')
        ->not->toContain('Retired');
})->with([
    'read' => fn (): string => RedisControlScripts::read(),
    'compare and swap' => fn (): string => RedisControlScripts::compareAndSwap(),
]);
