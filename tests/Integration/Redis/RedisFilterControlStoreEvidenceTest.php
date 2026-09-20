<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('redis')]
final class RedisFilterControlStoreEvidenceTest extends TestCase
{
    private string $prefix;

    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisFilterControlStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'lbgcontrolevidence'.bin2hex(random_bytes(8));
        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix($this->prefix);
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
                "return redis.call('DEL', KEYS[1])",
                [$this->stateKey()],
                [],
            );
        } catch (Throwable) {
            // Cleanup must not mask the primary test failure.
        }

        parent::tearDown();
    }

    public function test_two_writers_conflict_and_loser_cannot_overwrite_winner(): void
    {
        $revisionOne = $this->state(
            revision: 1,
            lifecycle: LifecycleState::Configured,
            health: HealthState::Unavailable,
        );
        $this->store->compareAndSwap($this->filterName(), $revisionOne, null);

        $writerOne = $this->makeStore();
        $writerTwo = $this->makeStore();

        self::assertSame(1, $writerOne->read($this->filterName())?->revision()->value());
        self::assertSame(1, $writerTwo->read($this->filterName())?->revision()->value());

        $winner = $this->state(
            revision: 2,
            lifecycle: LifecycleState::Building,
            health: HealthState::Healthy,
        );
        $loser = $this->state(
            revision: 2,
            lifecycle: LifecycleState::Building,
            health: HealthState::Degraded,
        );

        $writerOne->compareAndSwap(
            $this->filterName(),
            $winner,
            FilterStateRevision::fromInt(1),
        );

        try {
            $writerTwo->compareAndSwap(
                $this->filterName(),
                $loser,
                FilterStateRevision::fromInt(1),
            );

            self::fail('Expected the stale writer to lose the Redis CAS race.');
        } catch (FilterControlWriteConflict) {
            $actual = $this->store->read($this->filterName());

            self::assertNotNull($actual);
            self::assertSame(2, $actual->revision()->value());
            self::assertSame(
                HealthState::Healthy,
                $actual->generations()[0]->health(),
            );
        }
    }

    public function test_wrong_redis_type_is_control_state_corruption(): void
    {
        $this->seed(
            "redis.call('SET', KEYS[1], 'wrong-type'); return 1",
        );

        $this->expectException(FilterControlStateCorrupt::class);

        $this->store->read($this->filterName());
    }

    public function test_malformed_control_v1_is_control_state_corruption(): void
    {
        $this->seed(<<<'LUA'
redis.call(
    'HSET',
    KEYS[1],
    'format', 'control-v1',
    'revision', '1',
    'last_allocated_version', '1',
    'candidate_version', '1',
    'g:1:lifecycle', 'configured'
)
return 1
LUA);

        $this->expectException(FilterControlStateCorrupt::class);

        $this->store->read($this->filterName());
    }

    public function test_unknown_control_field_is_control_state_corruption(): void
    {
        $this->seed(<<<'LUA'
redis.call(
    'HSET',
    KEYS[1],
    'format', 'control-v1',
    'revision', '1',
    'last_allocated_version', '1',
    'candidate_version', '1',
    'g:1:lifecycle', 'configured',
    'g:1:health', 'unavailable',
    'future_field', 'value'
)
return 1
LUA);

        $this->expectException(FilterControlStateCorrupt::class);

        $this->store->read($this->filterName());
    }

    public function test_unknown_control_format_is_control_state_corruption(): void
    {
        $this->seed(<<<'LUA'
redis.call(
    'HSET',
    KEYS[1],
    'format', 'control-v999',
    'revision', '1',
    'last_allocated_version', '1',
    'candidate_version', '1',
    'g:1:lifecycle', 'configured',
    'g:1:health', 'unavailable'
)
return 1
LUA);

        $this->expectException(FilterControlStateCorrupt::class);

        $this->store->read($this->filterName());
    }

    public function test_control_state_has_no_ttl_after_create_or_update(): void
    {
        $this->store->compareAndSwap(
            $this->filterName(),
            $this->state(1, LifecycleState::Configured, HealthState::Unavailable),
            null,
        );

        self::assertSame(-1, $this->ttl());

        $this->store->compareAndSwap(
            $this->filterName(),
            $this->state(2, LifecycleState::Building, HealthState::Healthy),
            FilterStateRevision::fromInt(1),
        );

        self::assertSame(-1, $this->ttl());
    }

    public function test_control_and_generation_keys_share_the_same_filter_hash_tag_by_construction(): void
    {
        $version = FilterVersion::fromInt(3);

        self::assertStringContainsString(
            '{products.sku}',
            $this->keyspace->stateKey($this->filterName()),
        );
        self::assertStringContainsString(
            '{products.sku}',
            $this->keyspace->metaKey($this->filterName(), $version),
        );
        self::assertStringContainsString(
            '{products.sku}',
            $this->keyspace->bitmapKey($this->filterName(), $version),
        );
    }

    private function makeStore(): RedisFilterControlStore
    {
        return new RedisFilterControlStore(
            executor: new RespRedisCommandExecutor(
                (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
                (int) (getenv('REDIS_PORT') ?: 6379),
            ),
            keyspace: $this->keyspace,
            codec: new RedisControlStateCodec,
        );
    }

    private function state(
        int $revision,
        LifecycleState $lifecycle,
        HealthState $health,
    ): FilterControlState {
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
                    lifecycle: $lifecycle,
                    health: $health,
                ),
            ],
        );
    }

    private function seed(string $script): void
    {
        self::assertSame(1, $this->executor->evaluate(
            $script,
            [$this->stateKey()],
            [],
        ));
    }

    private function ttl(): int
    {
        return $this->executor->evaluate(
            "return redis.call('TTL', KEYS[1])",
            [$this->stateKey()],
            [],
        );
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('products.sku');
    }

    private function stateKey(): string
    {
        return $this->keyspace->stateKey($this->filterName());
    }
}
