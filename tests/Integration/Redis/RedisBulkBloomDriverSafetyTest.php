<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('redis')]
final class RedisBulkBloomDriverSafetyTest extends TestCase
{
    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisBloomDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix(
            'lgbbulk'.bin2hex(random_bytes(8)),
        );
        $this->driver = new RedisBloomDriver(
            $this->executor,
            $this->keyspace,
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->driver->destroy($this->filterName(), $this->version());
        } catch (Throwable) {
            // Cleanup must not mask the primary failure.
        }

        parent::tearDown();
    }

    public function test_empty_bulk_batch_does_not_create_marker_or_storage(): void
    {
        $this->driver->addMany(
            $this->filterName(),
            $this->version(),
            [],
        );

        self::assertSame(0, $this->executor->evaluate(
            "return redis.call('EXISTS', KEYS[1])",
            [$this->metaKey()],
            [],
        ));
        self::assertSame(0, $this->executor->evaluate(
            "return redis.call('EXISTS', KEYS[1])",
            [$this->bitmapKey()],
            [],
        ));
    }

    public function test_representative_chunk_sets_marker_and_membership_without_ttl(): void
    {
        $this->driver->provision(
            $this->filterName(),
            $this->version(),
            $this->layout(),
        );

        $items = [];

        for ($index = 0; $index < 1000; $index++) {
            $items[] = $this->positions([
                $index % 32,
                ($index + 7) % 32,
                ($index + 19) % 32,
            ]);
        }

        $this->driver->addMany(
            $this->filterName(),
            $this->version(),
            $items,
        );

        self::assertSame(1, $this->markerIsCanonicalOne());
        self::assertTrue($this->driver->mightContain(
            $this->filterName(),
            $this->version(),
            $items[0],
        ));
        self::assertTrue($this->driver->mightContain(
            $this->filterName(),
            $this->version(),
            $items[999],
        ));
        self::assertSame(-1, $this->ttl($this->metaKey()));
        self::assertSame(-1, $this->ttl($this->bitmapKey()));
    }

    public function test_repeated_bulk_batch_keeps_marker_canonical(): void
    {
        $this->driver->provision(
            $this->filterName(),
            $this->version(),
            $this->layout(),
        );
        $item = $this->positions([1, 4, 7]);

        $this->driver->addMany(
            $this->filterName(),
            $this->version(),
            [$item],
        );
        $this->driver->addMany(
            $this->filterName(),
            $this->version(),
            [$item],
        );

        self::assertSame(1, $this->markerIsCanonicalOne());
    }

    public function test_malformed_existing_marker_is_storage_corruption_without_bit_mutation(): void
    {
        $this->driver->provision(
            $this->filterName(),
            $this->version(),
            $this->layout(),
        );
        $item = $this->positions([1, 4, 7]);

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], 'managed_bitmap_written', '2'); return 1",
            [$this->metaKey()],
            [],
        ));

        try {
            $this->driver->addMany(
                $this->filterName(),
                $this->version(),
                [$item],
            );

            self::fail('Expected malformed managed bitmap marker to fail.');
        } catch (BloomStorageCorrupt) {
            self::assertSame(0, $this->bitmapExists());
        }
    }

    public function test_written_marker_with_missing_bitmap_is_storage_corruption(): void
    {
        $this->driver->provision(
            $this->filterName(),
            $this->version(),
            $this->layout(),
        );
        $item = $this->positions([1, 4, 7]);

        $this->driver->addMany(
            $this->filterName(),
            $this->version(),
            [$item],
        );

        self::assertSame(1, $this->executor->evaluate(
            "return redis.call('DEL', KEYS[1])",
            [$this->bitmapKey()],
            [],
        ));
        self::assertSame(1, $this->markerIsCanonicalOne());

        $this->expectException(BloomStorageCorrupt::class);

        $this->driver->addMany(
            $this->filterName(),
            $this->version(),
            [$item],
        );
    }

    public function test_malformed_late_position_is_rejected_before_marker_or_bitmap_mutation(): void
    {
        $this->driver->provision(
            $this->filterName(),
            $this->version(),
            $this->layout(),
        );

        $status = $this->executor->evaluate(
            RedisBloomScripts::addMany(),
            [$this->metaKey(), $this->bitmapKey()],
            [
                'redis-bitmap-v1',
                '32',
                '3',
                'sha256-double-hash-v1',
                '1',
                '4',
                '7',
                '2',
                '5',
                '99',
            ],
        );

        self::assertSame(RedisBloomScripts::STATUS_INVALID_BATCH, $status);
        self::assertSame(0, $this->markerExists());
        self::assertSame(0, $this->bitmapExists());
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    private function version(): FilterVersion
    {
        return FilterVersion::fromInt(1);
    }

    private function layout(): BloomLayout
    {
        return BloomLayout::create(
            32,
            3,
            ProbeAlgorithm::Sha256DoubleHashV1,
        );
    }

    /**
     * @param  list<int>  $values
     */
    private function positions(array $values): BitPositions
    {
        return BitPositions::forLayout($this->layout(), $values);
    }

    private function metaKey(): string
    {
        return $this->keyspace->metaKey(
            $this->filterName(),
            $this->version(),
        );
    }

    private function bitmapKey(): string
    {
        return $this->keyspace->bitmapKey(
            $this->filterName(),
            $this->version(),
        );
    }

    private function markerExists(): int
    {
        return $this->executor->evaluate(
            "return redis.call('HEXISTS', KEYS[1], 'managed_bitmap_written')",
            [$this->metaKey()],
            [],
        );
    }

    private function markerIsCanonicalOne(): int
    {
        return $this->executor->evaluate(
            "return redis.call('HGET', KEYS[1], 'managed_bitmap_written') == '1' and 1 or 0",
            [$this->metaKey()],
            [],
        );
    }

    private function bitmapExists(): int
    {
        return $this->executor->evaluate(
            "return redis.call('EXISTS', KEYS[1])",
            [$this->bitmapKey()],
            [],
        );
    }

    private function ttl(string $key): int
    {
        return $this->executor->evaluate(
            "return redis.call('TTL', KEYS[1])",
            [$key],
            [],
        );
    }
}
