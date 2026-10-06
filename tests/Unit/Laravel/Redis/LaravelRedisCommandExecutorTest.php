<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\PhpRedisConnection;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Laravel\Redis\LaravelRedisCommandExecutor;
use Kefyusuf\BloomGate\Tests\Support\Redis\FakeRedisClientException;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingEvalshaIlluminateConnection as RecordingIlluminateRedisConnection;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingNativeEvalshaClient;
use Predis\Response\ServerException;

function makeLaravelRedisClientFailure(string $class, string $message = 'Redis client failure.'): Throwable
{
    if ($class === 'Predis\\PredisException' && class_exists(ServerException::class)) {
        return new ServerException($message);
    }

    if (class_exists($class) === false) {
        class_alias(FakeRedisClientException::class, $class);
    }

    if (is_a($class, Throwable::class, true) === false) {
        throw new LogicException(sprintf('Redis client failure class [%s] is not throwable.', $class));
    }

    /** @var class-string<Throwable> $class */
    return new $class($message);
}

it('forwards eval through the resolved Illuminate Redis connection exactly', function (): void {
    $connection = new RecordingIlluminateRedisConnection(42);
    $executor = new LaravelRedisCommandExecutor($connection);

    expect($executor->evaluate(
        'return 42',
        ['lbg:{users.email}:v:1:meta', 'lbg:{users.email}:v:1:bf'],
        ['32', '3'],
    ))->toBe(42)
        ->and($connection->evalCalls())->toBe([[
            'script' => 'return 42',
            'numberOfKeys' => 2,
            'arguments' => [
                'lbg:{users.email}:v:1:meta',
                'lbg:{users.email}:v:1:bf',
                '32',
                '3',
            ],
        ]]);
});

it('rejects non-integer Redis eval replies as a protocol error', function (): void {
    $executor = new LaravelRedisCommandExecutor(
        new RecordingIlluminateRedisConnection('42'),
    );

    expect(fn (): int => $executor->evaluate('return 42', [], []))
        ->toThrow(UnexpectedValueException::class);
});

it('normalizes supported Redis client failures', function (string $class): void {
    $failure = makeLaravelRedisClientFailure($class);
    $executor = new LaravelRedisCommandExecutor(
        new RecordingIlluminateRedisConnection(0, $failure),
    );

    try {
        $executor->evaluate('return 42', [], []);

        throw new LogicException('Expected RedisCommandFailed.');
    } catch (RedisCommandFailed $mapped) {
        expect($mapped->getPrevious())->toBe($failure);
    }
})->with([
    'phpredis' => 'RedisException',
    'predis' => 'Predis\\PredisException',
]);

it('does not mask non-Redis programming or configuration failures', function (): void {
    $failure = new LogicException('Programming failure.');
    $executor = new LaravelRedisCommandExecutor(
        new RecordingIlluminateRedisConnection(0, $failure),
    );

    try {
        $executor->evaluate('return 42', [], []);

        throw new LogicException('Expected the original failure.');
    } catch (LogicException $actual) {
        expect($actual)->toBe($failure);
    }
});

it('implements both legacy and structured redis executor contracts', function (): void {
    $executor = new LaravelRedisCommandExecutor(
        new RecordingIlluminateRedisConnection(0),
    );

    expect($executor)->toBeInstanceOf(RedisCommandExecutor::class)
        ->and($executor)->toBeInstanceOf(RedisStructuredCommandExecutor::class);
});

it('returns a strict list of strings from structured eval', function (): void {
    $connection = new RecordingIlluminateRedisConnection([
        'control-v1',
        '7',
        '3',
    ]);
    $executor = new LaravelRedisCommandExecutor($connection);

    expect($executor->evaluateStructured(
        'return {"control-v1", "7", "3"}',
        ['lbg:{users.email}:state'],
        [],
    ))->toBe([
        'control-v1',
        '7',
        '3',
    ])->and($connection->evalCalls())->toBe([[
        'script' => 'return {"control-v1", "7", "3"}',
        'numberOfKeys' => 1,
        'arguments' => ['lbg:{users.email}:state'],
    ]]);
});

it('accepts an empty structured eval list', function (): void {
    $executor = new LaravelRedisCommandExecutor(
        new RecordingIlluminateRedisConnection([]),
    );

    expect($executor->evaluateStructured('return {}', [], []))->toBe([]);
});

it('rejects unexpected structured eval reply shapes', function (mixed $reply): void {
    $executor = new LaravelRedisCommandExecutor(
        new RecordingIlluminateRedisConnection($reply),
    );

    expect(fn (): array => $executor->evaluateStructured('return ARGV', [], []))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'scalar integer' => 1,
    'scalar string' => 'control-v1',
    'associative array' => [['format' => 'control-v1']],
    'integer member' => [['control-v1', 1]],
    'nested member' => [['control-v1', ['revision']]],
    'null member' => [['control-v1', null]],
]);

it('normalizes supported redis client failures for structured eval', function (string $class): void {
    $failure = makeLaravelRedisClientFailure($class);
    $executor = new LaravelRedisCommandExecutor(
        new RecordingIlluminateRedisConnection([], $failure),
    );

    try {
        $executor->evaluateStructured('return {}', [], []);

        throw new LogicException('Expected RedisCommandFailed.');
    } catch (RedisCommandFailed $mapped) {
        expect($mapped->getPrevious())->toBe($failure);
    }
})->with([
    'phpredis' => 'RedisException',
    'predis' => 'Predis\\PredisException',
]);

it('does not mask programming failures during structured eval', function (): void {
    $failure = new LogicException('Structured programming failure.');
    $executor = new LaravelRedisCommandExecutor(
        new RecordingIlluminateRedisConnection([], $failure),
    );

    try {
        $executor->evaluateStructured('return {}', [], []);

        throw new LogicException('Expected the original failure.');
    } catch (LogicException $actual) {
        expect($actual)->toBe($failure);
    }
});

it('uses a warm EVALSHA without sending the Lua source or issuing EVAL', function (): void {
    $script = "return {'warm'}";
    $connection = new RecordingIlluminateRedisConnection(['warm'], scriptCached: true);
    $executor = new LaravelRedisCommandExecutor($connection);
    expect($executor->evaluateStructured($script, ['key'], ['arg']))->toBe(['warm'])
        ->and($connection->shaCalls())->toBe([[
            'hash' => sha1($script), 'numberOfKeys' => 1, 'arguments' => ['key', 'arg'],
        ]])
        ->and($connection->evalCalls())->toBe([]);
});

it('falls back exactly once only for operational NOSCRIPT errors', function (string $class): void {
    $script = "return redis.call('INCR', KEYS[1])";
    $connection = new RecordingIlluminateRedisConnection(1,
        shaFailure: makeLaravelRedisClientFailure($class, 'NOSCRIPT No matching script. Please use EVAL.'));
    expect((new LaravelRedisCommandExecutor($connection))->evaluate($script, ['counter'], ['opaque']))->toBe(1)
        ->and($connection->shaCalls())->toBe([[
            'hash' => sha1($script), 'numberOfKeys' => 1, 'arguments' => ['counter', 'opaque'],
        ]])
        ->and($connection->evalCalls())->toBe([[
            'script' => $script, 'numberOfKeys' => 1, 'arguments' => ['counter', 'opaque'],
        ]]);
})->with(['RedisException', 'Predis\\PredisException']);

it('never replays an operational error that does not prove NOSCRIPT', function (string $message): void {
    $failure = makeLaravelRedisClientFailure('RedisException', $message);
    $connection = new RecordingIlluminateRedisConnection(1, shaFailure: $failure);
    try {
        (new LaravelRedisCommandExecutor($connection))->evaluate('return 1', [], []);
        throw new LogicException('Expected operational failure.');
    } catch (RedisCommandFailed $mapped) {
        expect($mapped->getPrevious())->toBe($failure)
            ->and($connection->shaCalls())->toHaveCount(1)
            ->and($connection->evalCalls())->toBe([]);
    }
})->with(['read timeout', 'NOAUTH Authentication required.', 'READONLY replica',
    'ERR Error running script: NOSCRIPT is a user value', 'NOSCRIPTING unsupported',
    'ERR NOSCRIPT No matching script. Please use EVAL.']);

it('does not retry an EVAL fallback that itself fails', function (): void {
    $failure = makeLaravelRedisClientFailure('RedisException', 'NOSCRIPT No matching script. Please use EVAL.');
    $connection = new RecordingIlluminateRedisConnection(1, failure: $failure);
    expect(fn () => (new LaravelRedisCommandExecutor($connection))->evaluate('return 1', [], []))
        ->toThrow(RedisCommandFailed::class)
        ->and($connection->shaCalls())->toHaveCount(1)
        ->and($connection->evalCalls())->toHaveCount(1);
});

it('propagates programming failures containing NOSCRIPT without fallback', function (): void {
    $failure = new LogicException('NOSCRIPT No matching script. Please use EVAL.');
    $connection = new RecordingIlluminateRedisConnection(1, shaFailure: $failure);
    expect(fn () => (new LaravelRedisCommandExecutor($connection))->evaluate('return 1', [], []))
        ->toThrow(LogicException::class)
        ->and($connection->evalCalls())->toBe([]);
});

it('uses the native phpredis SHA signature and safely consumes only current NOSCRIPT errors', function (): void {
    $client = new RecordingNativeEvalshaClient(false, 'NOSCRIPT No matching script. Please use EVAL.');
    $executor = new LaravelRedisCommandExecutor(new PhpRedisConnection($client));
    expect($executor->evaluate('return 42', ['key'], ['arg']))->toBe(42)
        ->and($client->shaCalls)->toBe([[sha1('return 42'), ['key', 'arg'], 1]])
        ->and($client->clears)->toBe(1)
        ->and($client->evalCalls)->toBe(1);
})->skip(fn (): bool => ! extension_loaded('redis'), 'Requires the native phpredis extension.');

it('does not treat stale native errors or non-NOSCRIPT native errors as retry permission', function (?string $currentError): void {
    $client = new RecordingNativeEvalshaClient(false, $currentError, 'NOSCRIPT No matching script. Please use EVAL.');
    $executor = new LaravelRedisCommandExecutor(new PhpRedisConnection($client));
    expect(fn () => $executor->evaluate('return false', [], []))
        ->toThrow($currentError === null ? UnexpectedValueException::class : RedisCommandFailed::class)
        ->and($client->clears)->toBe(1)
        ->and($client->evalCalls)->toBe(0);
})->with([null, 'NOAUTH Authentication required.', 'READONLY replica', 'NOSCRIPT user-defined Lua error'])
    ->skip(fn (): bool => ! extension_loaded('redis'), 'Requires the native phpredis extension.');
