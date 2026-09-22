<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractConflict;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingRedisStructuredCommandExecutor;

function redisGenerationContractLayout(): BloomLayout
{
    return BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
}

function redisGenerationContractName(): FilterName
{
    return FilterName::fromString('users.email');
}

function redisGenerationContractVersion(): FilterVersion
{
    return FilterVersion::fromInt(2);
}

function redisGenerationSemanticContract(string $seed = 'a'): GenerationSemanticContract
{
    return new GenerationSemanticContract(
        normalizationFingerprint: NormalizationFingerprint::fromString(
            'sha256:'.str_repeat($seed, 64),
        ),
        authoritativeSetFingerprint: AuthoritativeSetFingerprint::fromString(
            'sha256:'.str_repeat(chr(ord($seed) + 1), 64),
        ),
        consistencyFingerprint: ConsistencyFingerprint::fromString(
            'sha256:'.str_repeat(chr(ord($seed) + 2), 64),
        ),
    );
}

function makeRedisGenerationContractStore(
    RecordingRedisStructuredCommandExecutor $executor,
): RedisGenerationContractStore {
    return new RedisGenerationContractStore(
        executor: $executor,
        keyspace: RedisKeyspace::fromPrefix('lbg'),
    );
}

/**
 * @return list<string>
 */
function redisGenerationReadResponse(
    ?GenerationSemanticContract $contract,
): array {
    return [
        RedisGenerationContractScripts::STATUS_OK,
        'redis-bitmap-v1',
        '32',
        '3',
        'sha256-double-hash-v1',
        $contract?->normalizationFingerprint()->value() ?? '',
        $contract?->authoritativeSetFingerprint()->value() ?? '',
        $contract?->consistencyFingerprint()->value() ?? '',
    ];
}

it('inspects exact provisioned layout from canonical redis metadata', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        redisGenerationReadResponse(null),
    ]);

    $layout = makeRedisGenerationContractStore($executor)->layout(
        redisGenerationContractName(),
        redisGenerationContractVersion(),
    );

    expect($layout)->not->toBeNull()
        ->and($layout?->equals(redisGenerationContractLayout()))->toBeTrue()
        ->and($executor->calls())->toBe([[
            'mode' => 'structured',
            'script' => RedisGenerationContractScripts::read(),
            'keys' => [
                'lbg:{users.email}:v:2:meta',
                'lbg:{users.email}:v:2:bf',
            ],
            'arguments' => [],
        ]]);
});

it('reports old provisioned m3 generation as unbound instead of corrupt', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([
        redisGenerationReadResponse(null),
    ]);

    expect(makeRedisGenerationContractStore($executor)->read(
        redisGenerationContractName(),
        redisGenerationContractVersion(),
    ))->toBeNull();
});

it('reconstructs a bound descriptor from redis layout and fingerprints', function (): void {
    $contract = redisGenerationSemanticContract();
    $executor = new RecordingRedisStructuredCommandExecutor([
        redisGenerationReadResponse($contract),
    ]);

    $descriptor = makeRedisGenerationContractStore($executor)->read(
        redisGenerationContractName(),
        redisGenerationContractVersion(),
    );

    expect($descriptor)->not->toBeNull()
        ->and($descriptor?->layout()->equals(redisGenerationContractLayout()))->toBeTrue()
        ->and($descriptor?->semanticContract()->equals($contract))->toBeTrue();
});

it('binds canonical layout and all fingerprints in one structured eval', function (): void {
    $contract = redisGenerationSemanticContract();
    $executor = new RecordingRedisStructuredCommandExecutor([[
        RedisGenerationContractScripts::STATUS_OK,
    ]]);

    makeRedisGenerationContractStore($executor)->bind(
        redisGenerationContractName(),
        redisGenerationContractVersion(),
        redisGenerationContractLayout(),
        $contract,
    );

    expect($executor->calls())->toBe([[
        'mode' => 'structured',
        'script' => RedisGenerationContractScripts::bind(),
        'keys' => [
            'lbg:{users.email}:v:2:meta',
            'lbg:{users.email}:v:2:bf',
        ],
        'arguments' => [
            'redis-bitmap-v1',
            '32',
            '3',
            'sha256-double-hash-v1',
            $contract->normalizationFingerprint()->value(),
            $contract->authoritativeSetFingerprint()->value(),
            $contract->consistencyFingerprint()->value(),
        ],
    ]]);
});

it('maps semantic store status replies to typed failures', function (
    string $status,
    string $exception,
): void {
    $executor = new RecordingRedisStructuredCommandExecutor([[$status]]);

    expect(fn () => makeRedisGenerationContractStore($executor)->bind(
        redisGenerationContractName(),
        redisGenerationContractVersion(),
        redisGenerationContractLayout(),
        redisGenerationSemanticContract(),
    ))->toThrow($exception);
})->with([
    'not provisioned' => [
        RedisGenerationContractScripts::STATUS_NOT_PROVISIONED,
        BloomFilterNotProvisioned::class,
    ],
    'storage corrupt' => [
        RedisGenerationContractScripts::STATUS_STORAGE_CORRUPT,
        BloomStorageCorrupt::class,
    ],
    'layout mismatch' => [
        RedisGenerationContractScripts::STATUS_LAYOUT_MISMATCH,
        BloomLayoutMismatch::class,
    ],
    'semantic conflict' => [
        RedisGenerationContractScripts::STATUS_CONTRACT_CONFLICT,
        GenerationContractConflict::class,
    ],
]);

it('maps read storage failures without interpreting them as unbound', function (
    string $status,
    string $exception,
): void {
    $executor = new RecordingRedisStructuredCommandExecutor([[$status]]);

    expect(fn () => makeRedisGenerationContractStore($executor)->read(
        redisGenerationContractName(),
        redisGenerationContractVersion(),
    ))->toThrow($exception);
})->with([
    'not provisioned' => [
        RedisGenerationContractScripts::STATUS_NOT_PROVISIONED,
        BloomFilterNotProvisioned::class,
    ],
    'storage corrupt' => [
        RedisGenerationContractScripts::STATUS_STORAGE_CORRUPT,
        BloomStorageCorrupt::class,
    ],
]);

it('wraps redis command failures as semantic store operational failures', function (): void {
    $redisFailure = new RedisCommandFailed('Redis command failed.');
    $executor = new RecordingRedisStructuredCommandExecutor([$redisFailure]);

    try {
        makeRedisGenerationContractStore($executor)->read(
            redisGenerationContractName(),
            redisGenerationContractVersion(),
        );

        throw new RuntimeException('Expected semantic store operational failure.');
    } catch (GenerationContractStoreOperationFailed $failure) {
        expect($failure->getPrevious())->toBe($redisFailure);
    }
});

it('rejects unexpected structured replies as protocol errors', function (): void {
    $executor = new RecordingRedisStructuredCommandExecutor([['999']]);

    expect(fn () => makeRedisGenerationContractStore($executor)->read(
        redisGenerationContractName(),
        redisGenerationContractVersion(),
    ))->toThrow(UnexpectedValueException::class);
});
