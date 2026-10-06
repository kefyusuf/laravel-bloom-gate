<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Drivers\Redis\RedisWriterSynchronizationStore;
use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use RuntimeException;

final class RedisWriterSynchronizationContractFixture implements WriterSynchronizationStoreContractFixture
{
    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisWriterSynchronizationStore $store;

    private RedisCoordinationCodec $codec;

    public function __construct()
    {
        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix(
            RedisTestKeyPrefix::unique('lbgwritercontract'),
        );
        $this->codec = new RedisCoordinationCodec;
        $this->store = new RedisWriterSynchronizationStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            coordinationCodec: $this->codec,
        );
    }

    public function store(): WriterSynchronizationStore
    {
        return $this->store;
    }

    public function putCoordination(
        FilterName $name,
        bool $ownershipClaimed,
        ?SynchronizationState $synchronization,
    ): void {
        $ownerKey = $this->keyspace->syncOwnerKey($name);
        $syncKey = $this->keyspace->syncKey($name);

        $this->deleteKeys([$ownerKey, $syncKey]);

        if ($ownershipClaimed) {
            $result = $this->executor->evaluate(
                "redis.call('SET', KEYS[1], ARGV[1]); return 1",
                [$ownerKey],
                [RedisCoordinationCodec::OWNER_VALUE],
            );

            if ($result !== 1) {
                throw new RuntimeException('Expected Redis ownership fixture seed to succeed.');
            }
        }

        if ($synchronization !== null) {
            $this->replaceHash(
                $syncKey,
                $this->codec->encodeSynchronization($synchronization),
            );
        }
    }

    public function setActiveWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
        int $count,
    ): void {
        $result = $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], ARGV[1], ARGV[2]); return 1",
            [$this->keyspace->syncCountsKey($name)],
            [
                $this->codec->encodeCountField($epoch),
                $this->codec->encodeCount($count),
            ],
        );

        if ($result !== 1) {
            throw new RuntimeException('Expected Redis active-writer count fixture seed to succeed.');
        }
    }

    public function removeActiveWriterCount(FilterName $name, ?SynchronizationEpoch $epoch): void
    {
        $this->executor->evaluate(
            $epoch === null ? "return redis.call('DEL', KEYS[1])" : "return redis.call('HDEL', KEYS[1], ARGV[1])",
            [$this->keyspace->syncCountsKey($name)],
            $epoch === null ? [] : [$this->codec->encodeCountField($epoch)],
        );
    }

    public function corruptActiveWriterCount(FilterName $name, SynchronizationEpoch $epoch): void
    {
        $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], ARGV[1], '-1'); return 1",
            [$this->keyspace->syncCountsKey($name)],
            [$this->codec->encodeCountField($epoch)],
        );
    }

    public function corruptLease(FilterName $name, WriterLeaseToken $token): void
    {
        $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], ARGV[1], 'invalid-lease'); return 1",
            [$this->keyspace->syncLeasesKey($name)],
            [$token->value()],
        );
    }

    public function rawWriterState(FilterName $name): array
    {
        $result = [];
        foreach (['counts' => $this->keyspace->syncCountsKey($name), 'leases' => $this->keyspace->syncLeasesKey($name)] as $field => $key) {
            $result[$field] = $this->executor->evaluateStructured("return redis.call('HGETALL', KEYS[1])", [$key], []);
        }

        return $result;
    }

    /**
     * @param  list<string>  $keys
     */
    private function deleteKeys(array $keys): void
    {
        $script = 'return redis.call(\'DEL\'';

        for ($index = 1; $index <= count($keys); $index++) {
            $script .= sprintf(', KEYS[%d]', $index);
        }

        $script .= ')';

        $this->executor->evaluate($script, $keys, []);
    }

    /**
     * @param  list<string>  $payload
     */
    private function replaceHash(
        string $key,
        array $payload,
    ): void {
        $result = $this->executor->evaluate(
            "redis.call('DEL', KEYS[1]); redis.call('HSET', KEYS[1], unpack(ARGV)); return 1",
            [$key],
            $payload,
        );

        if ($result !== 1) {
            throw new RuntimeException('Expected Redis hash fixture seed to succeed.');
        }
    }
}
