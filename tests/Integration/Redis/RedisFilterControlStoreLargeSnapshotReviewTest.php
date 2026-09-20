<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStoreOperationFailed;
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
final class RedisFilterControlStoreLargeSnapshotReviewTest extends TestCase
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
            'lbgreview'.bin2hex(random_bytes(8)),
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
                "return redis.call('DEL', KEYS[1])",
                [$this->keyspace->stateKey($this->filterName())],
                [],
            );
        } catch (Throwable) {
            // Review cleanup must not mask the finding.
        }

        parent::tearDown();
    }

    public function test_failed_large_snapshot_replacement_never_deletes_the_previous_state(): void
    {
        $lastAllocated = FilterVersion::fromInt(5000);
        $initial = new FilterControlState(
            filterName: $this->filterName(),
            revision: FilterStateRevision::fromInt(1),
            lastAllocatedVersion: $lastAllocated,
            activeVersion: null,
            candidateVersion: null,
            generations: [],
        );

        $this->store->compareAndSwap(
            $this->filterName(),
            $initial,
            null,
        );

        $generations = [];

        for ($version = 1; $version <= 5000; $version++) {
            $generations[] = new GenerationControlState(
                version: FilterVersion::fromInt($version),
                lifecycle: LifecycleState::Retired,
                health: HealthState::Unavailable,
            );
        }

        $large = new FilterControlState(
            filterName: $this->filterName(),
            revision: FilterStateRevision::fromInt(2),
            lastAllocatedVersion: $lastAllocated,
            activeVersion: null,
            candidateVersion: null,
            generations: $generations,
        );

        try {
            $this->store->compareAndSwap(
                $this->filterName(),
                $large,
                FilterStateRevision::fromInt(1),
            );
        } catch (FilterControlStoreOperationFailed) {
            // An implementation/runtime size limit is acceptable only if prior
            // correctness state survives the failed replacement.
        }

        $after = $this->store->read($this->filterName());

        self::assertNotNull(
            $after,
            'A failed control-state replacement must not delete the previous snapshot.',
        );
        self::assertContains(
            $after->revision()->value(),
            [1, 2],
            'The store must contain either the previous snapshot or the complete replacement.',
        );
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('products.sku');
    }
}
