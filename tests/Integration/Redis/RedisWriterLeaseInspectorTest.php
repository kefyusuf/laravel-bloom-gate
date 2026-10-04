<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Drivers\Redis\RedisWriterSynchronizationStore;
use Kefyusuf\BloomGate\Tests\Contract\WriterLeaseInspectorContractTestCase;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;

#[Group('redis')]
final class RedisWriterLeaseInspectorTest extends WriterLeaseInspectorContractTestCase
{
    protected function stores(): array
    {
        $executor = new RespRedisCommandExecutor((string) (getenv('REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('REDIS_PORT') ?: 6379));
        $keyspace = RedisKeyspace::fromPrefix(RedisTestKeyPrefix::unique('lbgwu10'));
        $codec = new RedisCoordinationCodec;

        return [
            new RedisCoordinatedLifecycleStore($executor, $keyspace, new RedisControlStateCodec, $codec),
            new RedisWriterSynchronizationStore($executor, $keyspace, $codec),
        ];
    }

    public function test_malformed_lease_registry_is_rejected_without_repair(): void
    {
        $executor = new RespRedisCommandExecutor((string) (getenv('REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('REDIS_PORT') ?: 6379));
        $keyspace = RedisKeyspace::fromPrefix(RedisTestKeyPrefix::unique('lbgwu10corrupt'));
        $name = FilterName::fromString('users.email');
        $key = $keyspace->syncLeasesKey($name);
        $executor->evaluate("redis.call('HSET', KEYS[1], 'bad-token', 'P|1|'); return 1", [$key], []);
        $store = new RedisWriterSynchronizationStore($executor, $keyspace, new RedisCoordinationCodec);
        try {
            $store->readActiveLeases($name);
            self::fail('Malformed lease registry must not produce valid diagnostics.');
        } catch (CoordinationStateCorrupt) {
            self::assertSame(1, $executor->evaluate("return redis.call('HEXISTS', KEYS[1], 'bad-token')", [$key], []));
        }
    }
}
