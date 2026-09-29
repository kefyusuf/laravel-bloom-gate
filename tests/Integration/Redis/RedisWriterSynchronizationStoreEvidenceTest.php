<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Drivers\Redis\RedisWriterSynchronizationStore;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('redis')]
final class RedisWriterSynchronizationStoreEvidenceTest extends TestCase
{
    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisCoordinationCodec $codec;

    private RedisWriterSynchronizationStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix(
            RedisTestKeyPrefix::unique('lbgwriterevidence'),
        );
        $this->codec = new RedisCoordinationCodec;
        $this->store = new RedisWriterSynchronizationStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            coordinationCodec: $this->codec,
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->executor->evaluate(
                "return redis.call('DEL', KEYS[1], KEYS[2], KEYS[3], KEYS[4], KEYS[5], KEYS[6], KEYS[7])",
                [
                    $this->keyspace->stateKey($this->filterName()),
                    $this->keyspace->stateStagingKey($this->filterName()),
                    $this->keyspace->syncOwnerKey($this->filterName()),
                    $this->keyspace->syncKey($this->filterName()),
                    $this->keyspace->syncStagingKey($this->filterName()),
                    $this->keyspace->syncLeasesKey($this->filterName()),
                    $this->keyspace->syncCountsKey($this->filterName()),
                ],
                [],
            );
        } catch (Throwable) {
            // Cleanup must not mask the primary test failure.
        }

        parent::tearDown();
    }

    public function test_malformed_owner_rejects_prepare_without_mutating_lease_or_count(): void
    {
        $this->seedCoordination($this->syncState(1, 1, [1, 2]));
        $token = $this->token('a');
        $this->store->acquire($this->filterName(), $token);

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('SET', KEYS[1], 'coordinated-v999'); return 1",
            [$this->keyspace->syncOwnerKey($this->filterName())],
            [],
        ));

        try {
            $this->store->markPrepared($this->filterName(), $token);
            self::fail('Expected malformed ownership to reject writer preparation.');
        } catch (CoordinationStateCorrupt) {
            self::addToAssertionCount(1);
        }

        $this->assertLeaseAndCount($token, 'A|1|1,2', '1');
    }

    public function test_malformed_current_sync_rejects_prepare_without_mutating_lease_or_count(): void
    {
        $this->seedCoordination($this->syncState(1, 1, [1, 2]));
        $token = $this->token('b');
        $this->store->acquire($this->filterName(), $token);

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], 'future_field', 'value'); return 1",
            [$this->keyspace->syncKey($this->filterName())],
            [],
        ));

        try {
            $this->store->markPrepared($this->filterName(), $token);
            self::fail('Expected malformed synchronization state to reject writer preparation.');
        } catch (CoordinationStateCorrupt) {
            self::addToAssertionCount(1);
        }

        $this->assertLeaseAndCount($token, 'A|1|1,2', '1');
    }

    public function test_malformed_lease_record_fails_closed_without_count_mutation(): void
    {
        $this->seedCoordination($this->syncState(1, 1, [1, 2]));
        $token = $this->token('c');

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], ARGV[1], 'A|1|2,1'); return 1",
            [$this->keyspace->syncLeasesKey($this->filterName())],
            [$token->value()],
        ));

        try {
            $this->store->release($this->filterName(), $token);
            self::fail('Expected malformed writer lease record to fail closed.');
        } catch (CoordinationStateCorrupt) {
            self::addToAssertionCount(1);
        }

        self::assertSame(
            0,
            $this->store->activeWriterCount(
                $this->filterName(),
                SynchronizationEpoch::fromInt(1),
            ),
        );
    }

    public function test_malformed_counter_blocks_release_without_mutating_lease_or_counter(): void
    {
        $this->seedCoordination($this->syncState(1, 1, [1, 2]));
        $token = $this->token('d');
        $this->store->acquire($this->filterName(), $token);

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], ARGV[1], '01'); return 1",
            [$this->keyspace->syncCountsKey($this->filterName())],
            [$this->codec->encodeCountField(SynchronizationEpoch::fromInt(1))],
        ));

        try {
            $this->store->release($this->filterName(), $token);
            self::fail('Expected malformed writer count to block release.');
        } catch (CoordinationStateCorrupt) {
            self::addToAssertionCount(1);
        }

        $this->assertLeaseAndCount($token, 'A|1|1,2', '01');
    }

    public function test_wrong_lease_and_count_redis_types_fail_closed(): void
    {
        $this->seedCoordination($this->syncState(1, 1, [1, 2]));
        $token = $this->token('e');

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('SET', KEYS[1], 'wrong-type'); return 1",
            [$this->keyspace->syncLeasesKey($this->filterName())],
            [],
        ));

        try {
            $this->store->acquire($this->filterName(), $token);
            self::fail('Expected wrong writer lease Redis type to fail closed.');
        } catch (CoordinationStateCorrupt) {
            self::addToAssertionCount(1);
        }

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('DEL', KEYS[1]); redis.call('SET', KEYS[2], 'wrong-type'); return 1",
            [
                $this->keyspace->syncLeasesKey($this->filterName()),
                $this->keyspace->syncCountsKey($this->filterName()),
            ],
            [],
        ));

        $this->expectException(CoordinationStateCorrupt::class);

        $this->store->activeWriterCount(
            $this->filterName(),
            SynchronizationEpoch::fromInt(1),
        );
    }

    public function test_writer_correctness_keys_never_receive_ttl(): void
    {
        $this->seedCoordination($this->syncState(1, 1, [1, 2]));
        $token = $this->token('f');

        $this->store->acquire($this->filterName(), $token);
        $this->store->markPrepared($this->filterName(), $token);

        $response = $this->executor->evaluateStructured(
            <<<'LUA'
return {
    tostring(redis.call('PTTL', KEYS[1])),
    tostring(redis.call('PTTL', KEYS[2])),
    tostring(redis.call('PTTL', KEYS[3])),
    tostring(redis.call('PTTL', KEYS[4]))
}
LUA,
            [
                $this->keyspace->syncOwnerKey($this->filterName()),
                $this->keyspace->syncKey($this->filterName()),
                $this->keyspace->syncLeasesKey($this->filterName()),
                $this->keyspace->syncCountsKey($this->filterName()),
            ],
            [],
        );

        self::assertSame(['-1', '-1', '-1', '-1'], $response);
    }

    public function test_epoch_rotation_and_acquire_have_only_complete_old_or_new_bindings(): void
    {
        $this->seedCoordination($this->syncState(1, 1, [1, 2]));

        $old = $this->store->acquire(
            $this->filterName(),
            $this->token('1'),
        );

        $lifecycle = new RedisCoordinatedLifecycleStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            controlCodec: new RedisControlStateCodec,
            coordinationCodec: $this->codec,
        );
        $lifecycle->compareAndSwapSynchronization(
            $this->filterName(),
            $this->syncState(2, 2, [2, 3]),
            SynchronizationRevision::fromInt(1),
            null,
        );

        $new = $this->store->acquire(
            $this->filterName(),
            $this->token('2'),
        );

        self::assertSame(1, $old->epoch()->value());
        self::assertSame([1, 2], $this->targetValues($old->targets()));
        self::assertSame(2, $new->epoch()->value());
        self::assertSame([2, 3], $this->targetValues($new->targets()));
        self::assertSame(
            1,
            $this->store->activeWriterCount(
                $this->filterName(),
                SynchronizationEpoch::fromInt(1),
            ),
        );
        self::assertSame(
            1,
            $this->store->activeWriterCount(
                $this->filterName(),
                SynchronizationEpoch::fromInt(2),
            ),
        );
    }

    private function seedCoordination(SynchronizationState $state): void
    {
        self::assertSame(1, $this->executor->evaluate(
            "redis.call('SET', KEYS[1], ARGV[1]); redis.call('DEL', KEYS[2]); redis.call('HSET', KEYS[2], unpack(ARGV, 2)); return 1",
            [
                $this->keyspace->syncOwnerKey($this->filterName()),
                $this->keyspace->syncKey($this->filterName()),
            ],
            [
                RedisCoordinationCodec::OWNER_VALUE,
                ...$this->codec->encodeSynchronization($state),
            ],
        ));
    }

    private function assertLeaseAndCount(
        WriterLeaseToken $token,
        string $lease,
        string $count,
    ): void {
        $response = $this->executor->evaluateStructured(
            <<<'LUA'
return {
    redis.call('HGET', KEYS[1], ARGV[1]),
    redis.call('HGET', KEYS[2], ARGV[2])
}
LUA,
            [
                $this->keyspace->syncLeasesKey($this->filterName()),
                $this->keyspace->syncCountsKey($this->filterName()),
            ],
            [
                $token->value(),
                $this->codec->encodeCountField(SynchronizationEpoch::fromInt(1)),
            ],
        );

        self::assertSame([$lease, $count], $response);
    }

    /**
     * @param  list<int>  $targets
     */
    private function syncState(
        int $revision,
        int $epoch,
        array $targets,
    ): SynchronizationState {
        return new SynchronizationState(
            revision: SynchronizationRevision::fromInt($revision),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt($epoch),
            currentTargets: SynchronizationTargetSet::fromVersions(array_map(
                static fn (int $version): FilterVersion => FilterVersion::fromInt($version),
                $targets,
            )),
            candidateVersion: null,
            drainingEpoch: null,
        );
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('orders.external_id');
    }

    private function token(string $suffix): WriterLeaseToken
    {
        return WriterLeaseToken::fromString(str_repeat($suffix, 32));
    }

    /**
     * @return list<int>
     */
    private function targetValues(SynchronizationTargetSet $targets): array
    {
        return array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $targets->versions(),
        );
    }
}
