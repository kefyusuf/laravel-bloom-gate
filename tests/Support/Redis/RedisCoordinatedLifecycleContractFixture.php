<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Contract\Support\CoordinatedLifecycleStoreContractFixture;

final class RedisCoordinatedLifecycleContractFixture implements CoordinatedLifecycleStoreContractFixture
{
    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisCoordinatedLifecycleStore $store;

    public function __construct()
    {
        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix(
            RedisTestKeyPrefix::unique('lbglifecyclecontract'),
        );
        $this->store = new RedisCoordinatedLifecycleStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            controlCodec: new RedisControlStateCodec,
            coordinationCodec: new RedisCoordinationCodec,
        );
    }

    public function store(): CoordinatedLifecycleStore
    {
        return $this->store;
    }

    public function putControl(
        FilterName $name,
        ?FilterControlState $control,
    ): void {
        $key = $this->keyspace->stateKey($name);

        if ($control === null) {
            $this->deleteKeys([$key]);

            return;
        }

        $this->replaceHash(
            $key,
            (new RedisControlStateCodec)->encode($control),
        );
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
                throw new \RuntimeException('Expected Redis ownership fixture seed to succeed.');
            }
        }

        if ($synchronization !== null) {
            $this->replaceHash(
                $syncKey,
                (new RedisCoordinationCodec)->encodeSynchronization(
                    $synchronization,
                ),
            );
        }
    }

    public function putStagingSynchronization(
        FilterName $name,
        SynchronizationState $synchronization,
    ): void {
        $this->replaceHash(
            $this->keyspace->syncStagingKey($name),
            (new RedisCoordinationCodec)->encodeSynchronization(
                $synchronization,
            ),
        );
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
            throw new \RuntimeException('Expected Redis hash fixture seed to succeed.');
        }
    }
}
