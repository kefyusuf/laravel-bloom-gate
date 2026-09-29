<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('redis')]
final class RedisCoordinationScriptsEvidenceTest extends TestCase
{
    public function test_atomic_read_accepts_a_canonical_owned_sync_snapshot(): void
    {
        $executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $keyspace = RedisKeyspace::fromPrefix(
            RedisTestKeyPrefix::unique('lbgcoordscript'),
        );
        $name = FilterName::fromString('users.email');
        $codec = new RedisCoordinationCodec;
        $sync = new SynchronizationState(
            revision: SynchronizationRevision::fromInt(7),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt(1),
            currentTargets: SynchronizationTargetSet::fromVersions([
                FilterVersion::fromInt(1),
            ]),
            candidateVersion: null,
            drainingEpoch: null,
        );

        self::assertSame(1, $executor->evaluate(
            "redis.call('SET', KEYS[1], ARGV[1]); redis.call('HSET', KEYS[2], unpack(ARGV, 2)); return 1",
            [
                $keyspace->syncOwnerKey($name),
                $keyspace->syncKey($name),
            ],
            [
                RedisCoordinationCodec::OWNER_VALUE,
                ...$codec->encodeSynchronization($sync),
            ],
        ));

        $validatorResponse = $executor->evaluateStructured(
            RedisControlScripts::controlValidator()
                .PHP_EOL.RedisControlScripts::coordinationValidator()
                .PHP_EOL.<<<'LUA'
local fields = redis.call('HGETALL', KEYS[1])
local valid, revision = validateSynchronizationFields(fields)

return {
    valid and '1' or '0',
    revision or '',
}
LUA,
            [$keyspace->syncKey($name)],
            [],
        );

        self::assertSame(
            ['1', '7'],
            $validatorResponse,
            sprintf(
                'Unexpected raw sync validator response: [%s]',
                implode(', ', $validatorResponse),
            ),
        );

        $response = $executor->evaluateStructured(
            RedisCoordinationScripts::read(),
            [
                $keyspace->stateKey($name),
                $keyspace->syncOwnerKey($name),
                $keyspace->syncKey($name),
            ],
            [],
        );

        self::assertSame(
            '100',
            $response[0] ?? null,
            sprintf('Unexpected raw coordination read response: [%s]', implode(', ', $response)),
        );
        self::assertSame('1', $response[1] ?? null);
        self::assertSame('0', $response[2] ?? null);
        self::assertGreaterThan(0, (int) ($response[3] ?? '0'));
    }
}
