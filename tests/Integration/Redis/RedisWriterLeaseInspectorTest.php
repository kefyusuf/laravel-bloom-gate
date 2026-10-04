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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string}> */
    public static function invalidCountRegistries(): iterable
    {
        yield 'wrong Redis type' => ["redis.call('SET', KEYS[1], 'wrong-type'); return 1"];
        yield 'noncanonical epoch' => ["redis.call('HSET', KEYS[1], 'e:01', '0'); return 1"];
        yield 'noncanonical count' => ["redis.call('HSET', KEYS[1], 'e:1', '01'); return 1"];
    }

    #[DataProvider('invalidCountRegistries')]
    public function test_invalid_count_registry_is_rejected_without_repair(string $seed): void
    {
        $executor = new RespRedisCommandExecutor((string) (getenv('REDIS_HOST') ?: '127.0.0.1'), (int) (getenv('REDIS_PORT') ?: 6379));
        $keyspace = RedisKeyspace::fromPrefix(RedisTestKeyPrefix::unique('lbgcounts'));
        $name = FilterName::fromString('users.email');
        $key = $keyspace->syncCountsKey($name);
        $executor->evaluate($seed, [$key], []);
        $snapshot = "return {redis.call('DUMP', KEYS[1]) or ''}";
        $before = $executor->evaluateStructured($snapshot, [$key], []);
        $store = new RedisWriterSynchronizationStore($executor, $keyspace, new RedisCoordinationCodec);

        try {
            $store->readActiveLeases($name);
            self::fail('Invalid count registry must not produce valid diagnostics.');
        } catch (CoordinationStateCorrupt) {
            self::assertSame($before, $executor->evaluateStructured($snapshot, [$key], []));
        }
    }
}
