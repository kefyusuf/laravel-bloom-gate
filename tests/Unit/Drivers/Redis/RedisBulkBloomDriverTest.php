<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingRedisCommandExecutor;

function task6RedisLayout(): BloomLayout
{
    return BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
}

/**
 * @param  list<int>  $values
 */
function task6RedisPositions(array $values, ?BloomLayout $layout = null): BitPositions
{
    return BitPositions::forLayout(
        $layout ?? task6RedisLayout(),
        $values,
    );
}

function task6RedisDriver(
    RecordingRedisCommandExecutor $executor,
): RedisBloomDriver {
    return new RedisBloomDriver(
        $executor,
        RedisKeyspace::fromPrefix('lbg'),
    );
}

it('does not contact redis for an empty bulk batch', function (): void {
    $executor = new RecordingRedisCommandExecutor([]);

    task6RedisDriver($executor)->addMany(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        [],
    );

    expect($executor->calls())->toBe([]);
});

it('serializes a non-empty bulk batch into one redis eval', function (): void {
    $executor = new RecordingRedisCommandExecutor([
        RedisBloomScripts::STATUS_OK,
    ]);
    $driver = task6RedisDriver($executor);

    $driver->addMany(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        [
            task6RedisPositions([1, 4, 7]),
            task6RedisPositions([2, 5, 8]),
        ],
    );

    expect($executor->calls())->toBe([[
        'script' => RedisBloomScripts::addMany(),
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
            '2',
            '5',
            '8',
        ],
    ]]);
});

it('rejects mixed layouts before contacting redis', function (): void {
    $executor = new RecordingRedisCommandExecutor([]);
    $driver = task6RedisDriver($executor);

    expect(fn () => $driver->addMany(
        FilterName::fromString('users.email'),
        FilterVersion::fromInt(2),
        [
            task6RedisPositions([1, 4, 7]),
            task6RedisPositions(
                [2, 5, 8],
                BloomLayout::create(
                    64,
                    3,
                    ProbeAlgorithm::Sha256DoubleHashV1,
                ),
            ),
        ],
    ))->toThrow(BloomLayoutMismatch::class)
        ->and($executor->calls())->toBe([]);
});
