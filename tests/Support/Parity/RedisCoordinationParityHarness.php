<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Parity;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Drivers\Redis\RedisWriterSynchronizationStore;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use RuntimeException;

final class RedisCoordinationParityHarness implements CoordinationParityHarness
{
    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisControlStateCodec $controlCodec;

    private RedisCoordinationCodec $coordinationCodec;

    private RedisFilterControlStore $control;

    private RedisCoordinatedLifecycleStore $lifecycle;

    private RedisWriterSynchronizationStore $writer;

    /** @var array<string, FilterName> */
    private array $names = [];

    public function __construct()
    {
        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix(
            RedisTestKeyPrefix::unique('lbgparity'),
        );
        $this->controlCodec = new RedisControlStateCodec;
        $this->coordinationCodec = new RedisCoordinationCodec;
        $this->control = new RedisFilterControlStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            codec: $this->controlCodec,
        );
        $this->lifecycle = new RedisCoordinatedLifecycleStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            controlCodec: $this->controlCodec,
            coordinationCodec: $this->coordinationCodec,
        );
        $this->writer = new RedisWriterSynchronizationStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            coordinationCodec: $this->coordinationCodec,
        );
    }

    public function backend(): string
    {
        return 'redis';
    }

    public function control(): FilterControlStore
    {
        return $this->control;
    }

    public function lifecycle(): CoordinatedLifecycleStore
    {
        return $this->lifecycle;
    }

    public function writer(): WriterSynchronizationStore
    {
        return $this->writer;
    }

    public function putControl(
        FilterName $name,
        ?FilterControlState $control,
    ): void {
        $this->remember($name);
        $key = $this->keyspace->stateKey($name);

        if ($control === null) {
            $this->deleteKeys([$key]);

            return;
        }

        $this->replaceHash(
            $key,
            $this->controlCodec->encode($control),
        );
    }

    public function putCoordination(
        FilterName $name,
        bool $ownershipClaimed,
        ?SynchronizationState $synchronization,
    ): void {
        $this->remember($name);
        $ownerKey = $this->keyspace->syncOwnerKey($name);
        $syncKey = $this->keyspace->syncKey($name);

        $this->deleteKeys([$ownerKey, $syncKey]);

        if ($ownershipClaimed) {
            $this->assertIntegerResult(
                $this->executor->evaluate(
                    "redis.call('SET', KEYS[1], ARGV[1]); return 1",
                    [$ownerKey],
                    [RedisCoordinationCodec::OWNER_VALUE],
                ),
                'ownership seed',
            );
        }

        if ($synchronization !== null) {
            $this->replaceHash(
                $syncKey,
                $this->coordinationCodec->encodeSynchronization(
                    $synchronization,
                ),
            );
        }
    }

    public function putStagingSynchronization(
        FilterName $name,
        SynchronizationState $synchronization,
    ): void {
        $this->remember($name);
        $this->replaceHash(
            $this->keyspace->syncStagingKey($name),
            $this->coordinationCodec->encodeSynchronization(
                $synchronization,
            ),
        );
    }

    public function setActiveWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
        int $count,
    ): void {
        $this->remember($name);
        $this->assertIntegerResult(
            $this->executor->evaluate(
                "redis.call('HSET', KEYS[1], ARGV[1], ARGV[2]); return 1",
                [$this->keyspace->syncCountsKey($name)],
                [
                    $this->coordinationCodec->encodeCountField($epoch),
                    $this->coordinationCodec->encodeCount($count),
                ],
            ),
            'count seed',
        );
    }

    public function corruptSynchronization(FilterName $name): void
    {
        $this->remember($name);
        $this->replaceHash(
            $this->keyspace->syncKey($name),
            [
                'format', 'sync-v1',
                'revision', '1',
                'phase', 'STEADY',
                'current_epoch', '1',
                'current_targets', '-',
                'future_field', 'invalid',
            ],
        );
    }

    public function corruptLease(
        FilterName $name,
        WriterLeaseToken $token,
    ): void {
        $this->remember($name);
        $this->assertIntegerResult(
            $this->executor->evaluate(
                "redis.call('HSET', KEYS[1], ARGV[1], 'invalid-lease'); return 1",
                [$this->keyspace->syncLeasesKey($name)],
                [$token->value()],
            ),
            'lease corruption seed',
        );
    }

    public function corruptCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): void {
        $this->remember($name);
        $this->assertIntegerResult(
            $this->executor->evaluate(
                "redis.call('HSET', KEYS[1], ARGV[1], '-1'); return 1",
                [$this->keyspace->syncCountsKey($name)],
                [$this->coordinationCodec->encodeCountField($epoch)],
            ),
            'count corruption seed',
        );
    }

    public function cleanup(): void
    {
        foreach ($this->names as $name) {
            $this->deleteKeys([
                $this->keyspace->stateKey($name),
                $this->keyspace->stateStagingKey($name),
                $this->keyspace->syncOwnerKey($name),
                $this->keyspace->syncKey($name),
                $this->keyspace->syncStagingKey($name),
                $this->keyspace->syncLeasesKey($name),
                $this->keyspace->syncCountsKey($name),
            ]);
        }
    }

    private function remember(FilterName $name): void
    {
        $this->names[$name->value()] = $name;
    }

    /**
     * @param  list<string>  $keys
     */
    private function deleteKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

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
        $this->assertIntegerResult(
            $this->executor->evaluate(
                "redis.call('DEL', KEYS[1]); redis.call('HSET', KEYS[1], unpack(ARGV)); return 1",
                [$key],
                $payload,
            ),
            'hash seed',
        );
    }

    private function assertIntegerResult(
        int|string|array $result,
        string $operation,
    ): void {
        if ($result === 1) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Expected Redis parity fixture %s to succeed.',
            $operation,
        ));
    }
}
