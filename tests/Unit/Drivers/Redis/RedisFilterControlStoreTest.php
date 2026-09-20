<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingRedisStructuredCommandExecutor;

function redisControlStoreName(): FilterName
{
    return FilterName::fromString('products.sku');
}

function redisControlStoreState(int $revision): FilterControlState
{
    $name = redisControlStoreName();
    $version = FilterVersion::fromInt(1);

    return new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt($revision),
        lastAllocatedVersion: $version,
        activeVersion: null,
        candidateVersion: $version,
        generations: [
            new GenerationControlState(
                version: $version,
                lifecycle: LifecycleState::Configured,
                health: HealthState::Unavailable,
            ),
        ],
    );
}

function makeRedisControlStore(
    RecordingRedisStructuredCommandExecutor $executor,
): RedisFilterControlStore {
    return new RedisFilterControlStore(
        executor: $executor,
        keyspace: RedisKeyspace::fromPrefix('lbg'),
        codec: new RedisControlStateCodec,
    );
}

it('maps a missing redis control key to null', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['100'],
    ]);

    expect(makeRedisControlStore($executor)->read(redisControlStoreName()))->toBeNull()
        ->and($executor->structuredCalls())->toBe([[
            'script' => RedisControlScripts::read(),
            'keys' => ['lbg:{products.sku}:state'],
            'arguments' => [],
        ]]);
});

it('decodes a valid redis control hash payload', function (): void {
    $codec = new RedisControlStateCodec;
    $expected = redisControlStoreState(1);
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['100', ...$codec->encode($expected)],
    ]);

    $actual = makeRedisControlStore($executor)->read(redisControlStoreName());

    expect($actual)->not->toBeNull()
        ->and($actual?->revision()->value())->toBe(1)
        ->and($actual?->candidateVersion()?->value())->toBe(1)
        ->and($actual?->generations()[0]->lifecycle())->toBe(LifecycleState::Configured);
});

it('maps redis control read corruption status to the typed corruption failure', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['201'],
    ]);

    expect(fn () => makeRedisControlStore($executor)->read(redisControlStoreName()))
        ->toThrow(FilterControlStateCorrupt::class);
});

it('does not treat a malformed ok read payload as missing', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['100', 'format', 'control-v2'],
    ]);

    expect(fn () => makeRedisControlStore($executor)->read(redisControlStoreName()))
        ->toThrow(FilterControlStateCorrupt::class);
});

it('maps redis transport failures to control store operational failures', function (): void {
    $redisFailure = new RedisCommandFailed('transport failed');
    $executor = new RecordingRedisStructuredCommandExecutor([
        $redisFailure,
    ]);

    try {
        makeRedisControlStore($executor)->read(redisControlStoreName());

        throw new RuntimeException('Expected FilterControlStoreOperationFailed.');
    } catch (FilterControlStoreOperationFailed $failure) {
        expect($failure->getPrevious())->toBe($redisFailure);
    }
});

it('rejects unexpected read statuses as protocol errors', function (array $response): void {
    $executor = new RecordingRedisStructuredCommandExecutor([$response]);

    expect(fn () => makeRedisControlStore($executor)->read(redisControlStoreName()))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'empty response' => [[]],
    'conflict is invalid for read' => [['200']],
    'unknown status' => [['999']],
]);

it('creates control state with null expected revision and exact encoded payload', function (): void {
    $next = redisControlStoreState(1);
    $codec = new RedisControlStateCodec;
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['100'],
    ]);

    makeRedisControlStore($executor)->compareAndSwap(
        redisControlStoreName(),
        $next,
        null,
    );

    expect($executor->structuredCalls())->toBe([[
        'script' => RedisControlScripts::compareAndSwap(),
        'keys' => ['lbg:{products.sku}:state'],
        'arguments' => [
            '',
            ...$codec->encode($next),
        ],
    ]]);
});

it('updates control state with the exact expected revision token', function (): void {
    $next = redisControlStoreState(2);
    $codec = new RedisControlStateCodec;
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['100'],
    ]);

    makeRedisControlStore($executor)->compareAndSwap(
        redisControlStoreName(),
        $next,
        FilterStateRevision::fromInt(1),
    );

    expect($executor->structuredCalls()[0]['arguments'])->toBe([
        '1',
        ...$codec->encode($next),
    ]);
});

it('maps redis cas revision conflict without retrying', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['200'],
        ['100'],
    ]);

    expect(fn () => makeRedisControlStore($executor)->compareAndSwap(
        redisControlStoreName(),
        redisControlStoreState(2),
        FilterStateRevision::fromInt(1),
    ))->toThrow(FilterControlWriteConflict::class);

    expect($executor->structuredCalls())->toHaveCount(1);
});

it('maps redis cas storage corruption without retrying', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['201'],
        ['100'],
    ]);

    expect(fn () => makeRedisControlStore($executor)->compareAndSwap(
        redisControlStoreName(),
        redisControlStoreState(2),
        FilterStateRevision::fromInt(1),
    ))->toThrow(FilterControlStateCorrupt::class);

    expect($executor->structuredCalls())->toHaveCount(1);
});

it('maps redis cas transport failures to control store operational failures', function (): void {
    $redisFailure = new RedisCommandFailed('cas transport failed');
    $executor = new RecordingRedisStructuredCommandExecutor([
        $redisFailure,
    ]);

    try {
        makeRedisControlStore($executor)->compareAndSwap(
            redisControlStoreName(),
            redisControlStoreState(2),
            FilterStateRevision::fromInt(1),
        );

        throw new RuntimeException('Expected FilterControlStoreOperationFailed.');
    } catch (FilterControlStoreOperationFailed $failure) {
        expect($failure->getPrevious())->toBe($redisFailure);
    }
});

it('rejects unexpected cas reply shapes as protocol errors', function (array $response): void {
    $executor = new RecordingRedisStructuredCommandExecutor([$response]);

    expect(fn () => makeRedisControlStore($executor)->compareAndSwap(
        redisControlStoreName(),
        redisControlStoreState(2),
        FilterStateRevision::fromInt(1),
    ))->toThrow(UnexpectedValueException::class);
})->with([
    'empty response' => [[]],
    'success with payload' => [['100', 'unexpected']],
    'unknown status' => [['999']],
]);

it('rejects target snapshot identity mismatch before redis mutation', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['100'],
    ]);
    $otherName = FilterName::fromString('Products.Sku');
    $version = FilterVersion::fromInt(1);
    $next = new FilterControlState(
        filterName: $otherName,
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: $version,
        activeVersion: null,
        candidateVersion: $version,
        generations: [
            new GenerationControlState(
                $version,
                LifecycleState::Configured,
                HealthState::Unavailable,
            ),
        ],
    );

    expect(fn () => makeRedisControlStore($executor)->compareAndSwap(
        redisControlStoreName(),
        $next,
        null,
    ))->toThrow(InvalidArgumentException::class);

    expect($executor->structuredCalls())->toBe([]);
});

it('rejects invalid create revision before redis mutation', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['100'],
    ]);

    expect(fn () => makeRedisControlStore($executor)->compareAndSwap(
        redisControlStoreName(),
        redisControlStoreState(2),
        null,
    ))->toThrow(InvalidArgumentException::class);

    expect($executor->structuredCalls())->toBe([]);
});

it('rejects invalid update revision before redis mutation', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        ['100'],
    ]);

    expect(fn () => makeRedisControlStore($executor)->compareAndSwap(
        redisControlStoreName(),
        redisControlStoreState(3),
        FilterStateRevision::fromInt(1),
    ))->toThrow(InvalidArgumentException::class);

    expect($executor->structuredCalls())->toBe([]);
});
