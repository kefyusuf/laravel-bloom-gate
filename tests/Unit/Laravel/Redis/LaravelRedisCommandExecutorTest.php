<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Laravel\Redis\LaravelRedisCommandExecutor;
use Kefyusuf\BloomGate\Tests\Support\Redis\FakeRedisClientException;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingIlluminateRedisConnection;
use LogicException;
use Throwable;
use UnexpectedValueException;

function makeLaravelRedisClientFailure(string $class): Throwable
{
    if (! class_exists($class, false)) {
        class_alias(FakeRedisClientException::class, $class);
    }

    if (! is_a($class, Throwable::class, true)) {
        throw new LogicException(sprintf('Redis client failure class [%s] is not throwable.', $class));
    }

    /** @var class-string<Throwable> $class */
    return new $class('Redis client failure.');
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
