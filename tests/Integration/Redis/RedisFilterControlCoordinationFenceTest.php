<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('redis')]
final class RedisFilterControlCoordinationFenceTest extends TestCase
{
    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisFilterControlStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix(
            RedisTestKeyPrefix::unique('lbgcoordfence'),
        );
        $this->store = new RedisFilterControlStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            codec: new RedisControlStateCodec,
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

    public function test_unadopted_filter_keeps_existing_control_cas_behavior(): void
    {
        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(1),
            null,
        );

        self::assertSame(
            1,
            $this->store->read($this->filterName())?->revision()->value(),
        );
    }

    public function test_valid_coordination_owner_and_sync_fence_ordinary_control_cas(): void
    {
        $this->seedControlRevisionOne();
        $this->seedOwner(RedisCoordinationCodec::OWNER_VALUE);
        $this->seedSync($this->syncState());

        $this->expectException(CoordinationFenced::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(1),
        );
    }

    public function test_coordination_fence_wins_even_when_legacy_expected_revision_is_stale(): void
    {
        $this->seedControlRevisionOne();
        $this->seedOwner(RedisCoordinationCodec::OWNER_VALUE);
        $this->seedSync($this->syncState());

        $this->expectException(CoordinationFenced::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(9),
        );
    }

    public function test_owner_without_sync_is_coordination_corruption_for_ordinary_control_cas(): void
    {
        $this->seedControlRevisionOne();
        $this->seedOwner(RedisCoordinationCodec::OWNER_VALUE);

        $this->expectException(CoordinationStateCorrupt::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(1),
        );
    }

    public function test_sync_without_owner_is_coordination_corruption_for_ordinary_control_cas(): void
    {
        $this->seedControlRevisionOne();
        $this->seedSync($this->syncState());

        $this->expectException(CoordinationStateCorrupt::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(1),
        );
    }

    public function test_malformed_owner_value_is_coordination_corruption(): void
    {
        $this->seedControlRevisionOne();
        $this->seedOwner('coordinated-v999');
        $this->seedSync($this->syncState());

        $this->expectException(CoordinationStateCorrupt::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(1),
        );
    }

    public function test_wrong_owner_redis_type_is_coordination_corruption(): void
    {
        $this->seedControlRevisionOne();

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], 'x', 'y'); return 1",
            [$this->keyspace->syncOwnerKey($this->filterName())],
            [],
        ));
        $this->seedSync($this->syncState());

        $this->expectException(CoordinationStateCorrupt::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(1),
        );
    }

    public function test_wrong_sync_redis_type_is_coordination_corruption(): void
    {
        $this->seedControlRevisionOne();
        $this->seedOwner(RedisCoordinationCodec::OWNER_VALUE);

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('SET', KEYS[1], 'wrong-type'); return 1",
            [$this->keyspace->syncKey($this->filterName())],
            [],
        ));

        $this->expectException(CoordinationStateCorrupt::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(1),
        );
    }

    public function test_unknown_sync_field_is_coordination_corruption(): void
    {
        $this->seedControlRevisionOne();
        $this->seedOwner(RedisCoordinationCodec::OWNER_VALUE);

        $payload = [
            ...(new RedisCoordinationCodec)->encodeSynchronization($this->syncState()),
            'future_field', 'value',
        ];
        $this->seedRawSync($payload);

        $this->expectException(CoordinationStateCorrupt::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(1),
        );
    }

    public function test_coordination_corruption_wins_over_stale_legacy_revision(): void
    {
        $this->seedControlRevisionOne();
        $this->seedSync($this->syncState());

        $this->expectException(CoordinationStateCorrupt::class);

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(2),
            FilterStateRevision::fromInt(9),
        );
    }

    private function seedControlRevisionOne(): void
    {
        $this->store->compareAndSwap(
            $this->filterName(),
            $this->controlState(1),
            null,
        );
    }

    private function seedOwner(string $value): void
    {
        self::assertSame(1, $this->executor->evaluate(
            "redis.call('SET', KEYS[1], ARGV[1]); return 1",
            [$this->keyspace->syncOwnerKey($this->filterName())],
            [$value],
        ));
    }

    private function seedSync(SynchronizationState $state): void
    {
        $this->seedRawSync(
            (new RedisCoordinationCodec)->encodeSynchronization($state),
        );
    }

    /**
     * @param  list<string>  $payload
     */
    private function seedRawSync(array $payload): void
    {
        self::assertSame(1, $this->executor->evaluate(
            "redis.call('DEL', KEYS[1]); redis.call('HSET', KEYS[1], unpack(ARGV)); return 1",
            [$this->keyspace->syncKey($this->filterName())],
            $payload,
        ));
    }

    private function controlState(int $revision): FilterControlState
    {
        $version = FilterVersion::fromInt(1);

        return new FilterControlState(
            filterName: $this->filterName(),
            revision: FilterStateRevision::fromInt($revision),
            lastAllocatedVersion: $version,
            activeVersion: null,
            candidateVersion: $version,
            generations: [
                new GenerationControlState(
                    version: $version,
                    lifecycle: $revision === 1
                        ? LifecycleState::Configured
                        : LifecycleState::Building,
                    health: $revision === 1
                        ? HealthState::Unavailable
                        : HealthState::Healthy,
                ),
            ],
        );
    }

    private function syncState(): SynchronizationState
    {
        return new SynchronizationState(
            revision: SynchronizationRevision::fromInt(1),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt(1),
            currentTargets: SynchronizationTargetSet::fromVersions([]),
            candidateVersion: null,
            drainingEpoch: null,
        );
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('products.sku');
    }
}
