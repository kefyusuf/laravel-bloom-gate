<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutConflict;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingRedisCommandExecutor;
use RuntimeException;
use UnexpectedValueException;

function redisDriverLayout(): BloomLayout
{
    return BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
}

function redisDriverName(): FilterName
{
    return FilterName::fromString('users.email');
}

function redisDriverVersion(): FilterVersion
{
    return FilterVersion::fromInt(2);
}

function redisDriverPositions(): BitPositions
{
    return BitPositions::forLayout(redisDriverLayout(), [1, 4, 7]);
}

function makeRedisDriver(RecordingRedisCommandExecutor $executor): RedisBloomDriver
{
    return new RedisBloomDriver(
        $executor,
        RedisKeyspace::fromPrefix('lbg'),
    );
}

it('provisions with exact script keys and layout arguments', function (): void {
    $executor = new RecordingRedisCommandExecutor([RedisBloomScripts::STATUS_OK]);

    makeRedisDriver($executor)->provision(
        redisDriverName(),
        redisDriverVersion(),
        redisDriverLayout(),
    );

    expect($executor->calls())->toBe([[
        'script' => RedisBloomScripts::provision(),
        'keys' => [
            'lbg:{users.email}:v:2:meta',
            'lbg:{users.email}:v:2:bf',
        ],
        'arguments' => [
            'redis-bitmap-v1',
            '32',
            '3',
            'sha256-double-hash-v1',
        ],
    ]]);
});

it('adds with exact layout arguments followed by ordered positions', function (): void {
    $executor = new RecordingRedisCommandExecutor([RedisBloomScripts::STATUS_OK]);

    makeRedisDriver($executor)->add(
        redisDriverName(),
        redisDriverVersion(),
        redisDriverPositions(),
    );

    expect($executor->calls())->toBe([[
        'script' => RedisBloomScripts::add(),
        'keys' => [
            'lbg:{users.email}:v:2:meta',
            'lbg:{users.email}:v:2:bf',
        ],
        'arguments' => [
            'redis-bitmap-v1',
            '32',
            '3',
            'sha256-double-hash-v1',
            '1',
            '4',
            '7',
        ],
    ]]);
});

it('maps membership status codes to booleans', function (int $status, bool $expected): void {
    $executor = new RecordingRedisCommandExecutor([$status]);

    $actual = makeRedisDriver($executor)->mightContain(
        redisDriverName(),
        redisDriverVersion(),
        redisDriverPositions(),
    );

    expect($actual)->toBe($expected);
})->with([
    'absent' => [RedisBloomScripts::STATUS_MEMBERSHIP_ABSENT, false],
    'maybe present' => [RedisBloomScripts::STATUS_MEMBERSHIP_MAYBE_PRESENT, true],
]);

it('checks membership with exact script keys and ordered arguments', function (): void {
    $executor = new RecordingRedisCommandExecutor([
        RedisBloomScripts::STATUS_MEMBERSHIP_ABSENT,
    ]);

    makeRedisDriver($executor)->mightContain(
        redisDriverName(),
        redisDriverVersion(),
        redisDriverPositions(),
    );

    expect($executor->calls())->toBe([[
        'script' => RedisBloomScripts::mightContain(),
        'keys' => [
            'lbg:{users.email}:v:2:meta',
            'lbg:{users.email}:v:2:bf',
        ],
        'arguments' => [
            'redis-bitmap-v1',
            '32',
            '3',
            'sha256-double-hash-v1',
            '1',
            '4',
            '7',
        ],
    ]]);
});

it('destroys exactly the requested generation with no arguments', function (): void {
    $executor = new RecordingRedisCommandExecutor([RedisBloomScripts::STATUS_OK]);

    makeRedisDriver($executor)->destroy(
        redisDriverName(),
        redisDriverVersion(),
    );

    expect($executor->calls())->toBe([[
        'script' => RedisBloomScripts::destroy(),
        'keys' => [
            'lbg:{users.email}:v:2:meta',
            'lbg:{users.email}:v:2:bf',
        ],
        'arguments' => [],
    ]]);
});

it('maps provision semantic failures', function (int $status, string $exception): void {
    $executor = new RecordingRedisCommandExecutor([$status]);

    expect(fn () => makeRedisDriver($executor)->provision(
        redisDriverName(),
        redisDriverVersion(),
        redisDriverLayout(),
    ))->toThrow($exception);
})->with([
    'layout conflict' => [RedisBloomScripts::STATUS_LAYOUT_CONFLICT, BloomLayoutConflict::class],
    'storage corrupt' => [RedisBloomScripts::STATUS_STORAGE_CORRUPT, BloomStorageCorrupt::class],
]);

it('maps add semantic failures', function (int $status, string $exception): void {
    $executor = new RecordingRedisCommandExecutor([$status]);

    expect(fn () => makeRedisDriver($executor)->add(
        redisDriverName(),
        redisDriverVersion(),
        redisDriverPositions(),
    ))->toThrow($exception);
})->with([
    'not provisioned' => [RedisBloomScripts::STATUS_NOT_PROVISIONED, BloomFilterNotProvisioned::class],
    'layout mismatch' => [RedisBloomScripts::STATUS_LAYOUT_MISMATCH, BloomLayoutMismatch::class],
    'storage corrupt' => [RedisBloomScripts::STATUS_STORAGE_CORRUPT, BloomStorageCorrupt::class],
]);

it('maps membership semantic failures', function (int $status, string $exception): void {
    $executor = new RecordingRedisCommandExecutor([$status]);

    expect(fn () => makeRedisDriver($executor)->mightContain(
        redisDriverName(),
        redisDriverVersion(),
        redisDriverPositions(),
    ))->toThrow($exception);
})->with([
    'not provisioned' => [RedisBloomScripts::STATUS_NOT_PROVISIONED, BloomFilterNotProvisioned::class],
    'layout mismatch' => [RedisBloomScripts::STATUS_LAYOUT_MISMATCH, BloomLayoutMismatch::class],
    'storage corrupt' => [RedisBloomScripts::STATUS_STORAGE_CORRUPT, BloomStorageCorrupt::class],
]);

it('wraps redis command failures as driver operational failures', function (string $operation): void {
    $redisFailure = new RedisCommandFailed('Redis command failed.');
    $executor = new RecordingRedisCommandExecutor([$redisFailure]);
    $driver = makeRedisDriver($executor);

    try {
        switch ($operation) {
            case 'provision':
                $driver->provision(redisDriverName(), redisDriverVersion(), redisDriverLayout());
                break;
            case 'add':
                $driver->add(redisDriverName(), redisDriverVersion(), redisDriverPositions());
                break;
            case 'check':
                $driver->mightContain(redisDriverName(), redisDriverVersion(), redisDriverPositions());
                break;
            case 'destroy':
                $driver->destroy(redisDriverName(), redisDriverVersion());
                break;
            default:
                throw new RuntimeException('Unknown driver operation test fixture.');
        }

        throw new RuntimeException('Expected a BloomDriverOperationFailed exception.');
    } catch (BloomDriverOperationFailed $failure) {
        expect($failure->getPrevious())->toBe($redisFailure);
    }
})->with(['provision', 'add', 'check', 'destroy']);

it('rejects unexpected script status as a programming protocol error', function (): void {
    $executor = new RecordingRedisCommandExecutor([999]);

    expect(fn () => makeRedisDriver($executor)->provision(
        redisDriverName(),
        redisDriverVersion(),
        redisDriverLayout(),
    ))->toThrow(UnexpectedValueException::class);
});
