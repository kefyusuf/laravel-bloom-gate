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

it('keeps control reads on the canonical state key without ttl primitives', function (): void {
    $script = RedisControlScripts::read();

    expect($script)->toContain('KEYS[1]');
    expect($script)->not->toContain('KEYS[2]');
    expect($script)->not->toContain('EXPIRE');
    expect($script)->not->toContain('PEXPIRE');
    expect($script)->not->toContain('SETEX');
    expect($script)->not->toContain('PSETEX');
});

it('uses canonical state and same-slot staging keys for cas without ttl primitives', function (): void {
    $script = RedisControlScripts::compareAndSwap();

    expect($script)->toContain('KEYS[1]');
    expect($script)->toContain('KEYS[2]');
    expect($script)->not->toContain('KEYS[3]');
    expect($script)->not->toContain('EXPIRE');
    expect($script)->not->toContain('PEXPIRE');
    expect($script)->not->toContain('SETEX');
    expect($script)->not->toContain('PSETEX');
});

it('pins the private structured status tokens inside the scripts', function (): void {
    expect(RedisControlScripts::read())
        ->toContain("return {'100'}")
        ->toContain("return {'201'}");

    expect(RedisControlScripts::compareAndSwap())
        ->toContain("return {'100'}")
        ->toContain("return {'200'}")
        ->toContain("return {'201'}")
        ->toContain("return {'202'}");
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

it('materializes a complete staging hash before replacing current correctness state', function (): void {
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
    $stagingDelete = redisControlScriptMarker(
        $script,
        "redis.call('DEL', KEYS[2])",
    );
    $stagingWrite = redisControlScriptMarker(
        $script,
        'local writeOk, writeFailure = writeHashFields(KEYS[2], nextFields)',
    );
    $rename = redisControlScriptMarker(
        $script,
        "redis.pcall('RENAME', KEYS[2], KEYS[1])",
    );

    expect($typeCheck)->toBeLessThan($existingRead);
    expect($existingRead)->toBeLessThan($existingValidation);
    expect($existingValidation)->toBeLessThan($revisionCheck);
    expect($revisionCheck)->toBeLessThan($nextValidation);
    expect($nextValidation)->toBeLessThan($stagingDelete);
    expect($stagingDelete)->toBeLessThan($stagingWrite);
    expect($stagingWrite)->toBeLessThan($rename);

    expect($script)->not->toContain("redis.call('DEL', KEYS[1])");
    expect($script)->not->toContain('unpack(nextFields)');
});

it('bounds hset unpack calls while materializing the staging hash', function (): void {
    $script = RedisControlScripts::compareAndSwap();

    expect($script)->toContain('local WRITE_CHUNK_SIZE = 128');
    expect($script)->toContain("redis.pcall('HSET', key, unpack(chunk, 1, chunkCount))");
    expect($script)->not->toContain('unpack(nextFields)');
});

it('checks storage conflict before proposed revision progression', function (): void {
    $script = RedisControlScripts::compareAndSwap();

    $revisionConflict = redisControlScriptMarker(
        $script,
        'if currentRevision ~= expectedRevision then',
    );
    $nextValidation = redisControlScriptMarker(
        $script,
        'local nextValid, nextRevision = validateControlFields(nextFields)',
    );
    $nextRevisionIncrement = redisControlScriptMarker(
        $script,
        'requiredNextRevision = incrementCanonicalPositiveInteger(expectedRevision)',
    );
    $nextRevisionCheck = redisControlScriptMarker(
        $script,
        'if requiredNextRevision == nil or nextRevision ~= requiredNextRevision then',
    );

    expect($revisionConflict)->toBeLessThan($nextValidation);
    expect($nextValidation)->toBeLessThan($nextRevisionIncrement);
    expect($nextRevisionIncrement)->toBeLessThan($nextRevisionCheck);
});

it('places semantic cas failures before the first staging mutation', function (): void {
    $script = RedisControlScripts::compareAndSwap();
    $lastConflict = redisControlScriptLastMarker($script, "return {'200'}");
    $lastCorruption = redisControlScriptLastMarker($script, "return {'201'}");
    $lastInvalidRevision = redisControlScriptLastMarker($script, "return {'202'}");
    $stagingDelete = redisControlScriptMarker($script, "redis.call('DEL', KEYS[2])");

    expect($lastConflict)->toBeLessThan($stagingDelete);
    expect($lastCorruption)->toBeLessThan($stagingDelete);
    expect($lastInvalidRevision)->toBeLessThan($stagingDelete);
});

it('cleans staging on staged write or rename failure before returning the redis error', function (): void {
    $script = RedisControlScripts::compareAndSwap();

    expect($script)->toContain('if not writeOk then');
    expect($script)->toContain('return writeFailure');
    expect($script)->toContain('if type(renameResult) == \'table\' and renameResult.err ~= nil then');
    expect($script)->toContain('return renameResult');
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
