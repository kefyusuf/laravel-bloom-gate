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
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('redis')]
final class RedisBloomDriverCorruptionTest extends TestCase
{
    private string $prefix;

    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisBloomDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'lbgcorrupt'.bin2hex(random_bytes(8));
        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix($this->prefix);
        $this->driver = new RedisBloomDriver($this->executor, $this->keyspace);
    }

    protected function tearDown(): void
    {
        try {
            $this->driver->destroy($this->name(), $this->version());
        } catch (Throwable) {
            // Cleanup must not mask the primary test failure.
        }

        parent::tearDown();
    }

    public function test_orphan_bitmap_is_storage_corruption(): void
    {
        $this->seed("redis.call('SETBIT', KEYS[1], 7, 1); return 1", [
            $this->bitmapKey(),
        ]);

        $this->expectException(BloomStorageCorrupt::class);

        $this->driver->mightContain(
            $this->name(),
            $this->version(),
            $this->positions(),
        );
    }

    public function test_wrong_meta_type_is_storage_corruption(): void
    {
        $this->seed("redis.call('SET', KEYS[1], 'wrong-type'); return 1", [
            $this->metaKey(),
        ]);

        $this->expectException(BloomStorageCorrupt::class);

        $this->driver->provision(
            $this->name(),
            $this->version(),
            $this->layout(),
        );
    }

    public function test_wrong_bitmap_type_is_storage_corruption(): void
    {
        $this->driver->provision(
            $this->name(),
            $this->version(),
            $this->layout(),
        );

        $this->seed("redis.call('DEL', KEYS[1]); redis.call('HSET', KEYS[1], 'x', 'y'); return 1", [
            $this->bitmapKey(),
        ]);

        $this->expectException(BloomStorageCorrupt::class);

        $this->driver->mightContain(
            $this->name(),
            $this->version(),
            $this->positions(),
        );
    }

    public function test_missing_metadata_field_is_storage_corruption(): void
    {
        $this->seed(
            "redis.call('HSET', KEYS[1], 'format', 'redis-bitmap-v1', 'bit_count', '32', 'hash_count', '3'); return 1",
            [$this->metaKey()],
        );

        $this->expectException(BloomStorageCorrupt::class);

        $this->driver->mightContain(
            $this->name(),
            $this->version(),
            $this->positions(),
        );
    }

    public function test_malformed_numeric_metadata_is_storage_corruption(): void
    {
        $this->seed(
            "redis.call('HSET', KEYS[1], 'format', 'redis-bitmap-v1', 'bit_count', '032', 'hash_count', '3', 'probe_algorithm', 'sha256-double-hash-v1'); return 1",
            [$this->metaKey()],
        );

        $this->expectException(BloomStorageCorrupt::class);

        $this->driver->mightContain(
            $this->name(),
            $this->version(),
            $this->positions(),
        );
    }

    public function test_unknown_storage_format_is_storage_corruption(): void
    {
        $this->seed(
            "redis.call('HSET', KEYS[1], 'format', 'redis-bitmap-v999', 'bit_count', '32', 'hash_count', '3', 'probe_algorithm', 'sha256-double-hash-v1'); return 1",
            [$this->metaKey()],
        );

        $this->expectException(BloomStorageCorrupt::class);

        $this->driver->mightContain(
            $this->name(),
            $this->version(),
            $this->positions(),
        );
    }

    public function test_destroy_recovers_from_corrupt_generation_state(): void
    {
        $this->seed(
            "redis.call('SET', KEYS[1], 'wrong-type'); redis.call('HSET', KEYS[2], 'x', 'y'); return 1",
            [$this->metaKey(), $this->bitmapKey()],
        );

        $this->driver->destroy($this->name(), $this->version());
        $this->driver->provision($this->name(), $this->version(), $this->layout());

        self::assertFalse($this->driver->mightContain(
            $this->name(),
            $this->version(),
            $this->positions(),
        ));
    }

    public function test_unknown_probe_algorithm_is_layout_mismatch_not_corruption(): void
    {
        $this->seed(
            "redis.call('HSET', KEYS[1], 'format', 'redis-bitmap-v1', 'bit_count', '32', 'hash_count', '3', 'probe_algorithm', 'future-probe-v2'); return 1",
            [$this->metaKey()],
        );

        $this->expectException(\Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch::class);

        $this->driver->mightContain(
            $this->name(),
            $this->version(),
            $this->positions(),
        );
    }

    /**
     * @param list<string> $keys
     */
    private function seed(string $script, array $keys): void
    {
        self::assertSame(1, $this->executor->evaluate($script, $keys, []));
    }

    private function layout(): BloomLayout
    {
        return BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
    }

    private function positions(): BitPositions
    {
        return BitPositions::forLayout($this->layout(), [1, 4, 7]);
    }

    private function name(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    private function version(): FilterVersion
    {
        return FilterVersion::fromInt(1);
    }

    private function metaKey(): string
    {
        return $this->keyspace->metaKey($this->name(), $this->version());
    }

    private function bitmapKey(): string
    {
        return $this->keyspace->bitmapKey($this->name(), $this->version());
    }
}
