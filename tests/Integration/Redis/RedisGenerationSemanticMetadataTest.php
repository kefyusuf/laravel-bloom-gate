<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractConflict;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('redis')]
final class RedisGenerationSemanticMetadataTest extends TestCase
{
    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisBloomDriver $driver;

    private RedisGenerationContractStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix(RedisTestKeyPrefix::unique('lbgsemantic'));
        $this->driver = new RedisBloomDriver($this->executor, $this->keyspace);
        $this->store = new RedisGenerationContractStore($this->executor, $this->keyspace);
    }

    protected function tearDown(): void
    {
        foreach ([1, 2] as $version) {
            try {
                $this->driver->destroy($this->filterName(), FilterVersion::fromInt($version));
            } catch (Throwable) {
                // Cleanup must not mask the primary failure.
            }
        }

        parent::tearDown();
    }

    public function test_old_m3_generation_remains_low_level_usable_and_m5_unbound(): void
    {
        $version = FilterVersion::fromInt(1);
        $positions = $this->positions();

        $this->driver->provision($this->filterName(), $version, $this->layout());
        $this->driver->add($this->filterName(), $version, $positions);

        self::assertTrue($this->driver->mightContain($this->filterName(), $version, $positions));
        self::assertNull($this->store->read($this->filterName(), $version));
    }

    public function test_partial_semantic_binding_is_storage_corruption(): void
    {
        $version = FilterVersion::fromInt(1);
        $this->driver->provision($this->filterName(), $version, $this->layout());

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], 'normalization_fingerprint', ARGV[1]); return 1",
            [$this->keyspace->metaKey($this->filterName(), $version)],
            ['sha256:'.str_repeat('a', 64)],
        ));

        $this->expectException(BloomStorageCorrupt::class);

        $this->store->read($this->filterName(), $version);
    }

    public function test_wrong_meta_type_is_storage_corruption_for_managed_read(): void
    {
        $version = FilterVersion::fromInt(1);

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('SET', KEYS[1], 'wrong-type'); return 1",
            [$this->keyspace->metaKey($this->filterName(), $version)],
            [],
        ));

        $this->expectException(BloomStorageCorrupt::class);

        $this->store->read($this->filterName(), $version);
    }

    public function test_malformed_canonical_m3_metadata_is_storage_corruption_for_managed_read(): void
    {
        $version = FilterVersion::fromInt(1);
        $this->driver->provision($this->filterName(), $version, $this->layout());

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], 'bit_count', '032'); return 1",
            [$this->keyspace->metaKey($this->filterName(), $version)],
            [],
        ));

        $this->expectException(BloomStorageCorrupt::class);

        $this->store->read($this->filterName(), $version);
    }

    public function test_binding_preserves_bitmap_bits_unknown_m3_fields_and_no_ttl(): void
    {
        $version = FilterVersion::fromInt(1);
        $positions = $this->positions();

        $this->driver->provision($this->filterName(), $version, $this->layout());
        $this->driver->add($this->filterName(), $version, $positions);

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], 'future_field', 'future-value'); return 1",
            [$this->keyspace->metaKey($this->filterName(), $version)],
            [],
        ));

        $this->store->bind(
            $this->filterName(),
            $version,
            $this->layout(),
            $this->contract('a', 'b', 'c'),
        );

        self::assertTrue($this->driver->mightContain($this->filterName(), $version, $positions));
        self::assertSame(-1, $this->executor->evaluate(
            "return redis.call('TTL', KEYS[1])",
            [$this->keyspace->metaKey($this->filterName(), $version)],
            [],
        ));
        self::assertSame(-1, $this->executor->evaluate(
            "return redis.call('TTL', KEYS[1])",
            [$this->keyspace->bitmapKey($this->filterName(), $version)],
            [],
        ));
        self::assertSame(1, $this->executor->evaluate(
            "return redis.call('HEXISTS', KEYS[1], 'future_field')",
            [$this->keyspace->metaKey($this->filterName(), $version)],
            [],
        ));
    }

    public function test_two_independent_first_binders_have_one_winner_in_each_arrival_order(): void
    {
        $secondExecutor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $firstStore = new RedisGenerationContractStore($this->executor, $this->keyspace);
        $secondStore = new RedisGenerationContractStore($secondExecutor, $this->keyspace);

        $winnerA = $this->contract('1', '2', '3');
        $winnerB = $this->contract('4', '5', '6');

        $versionOne = FilterVersion::fromInt(1);
        $this->driver->provision($this->filterName(), $versionOne, $this->layout());

        $firstStore->bind($this->filterName(), $versionOne, $this->layout(), $winnerA);

        try {
            $secondStore->bind($this->filterName(), $versionOne, $this->layout(), $winnerB);
            self::fail('Expected second independent binder to lose.');
        } catch (GenerationContractConflict) {
            $descriptor = $firstStore->read($this->filterName(), $versionOne);
            self::assertNotNull($descriptor);
            self::assertTrue($winnerA->equals($descriptor->semanticContract()));
        }

        $versionTwo = FilterVersion::fromInt(2);
        $this->driver->provision($this->filterName(), $versionTwo, $this->layout());

        $secondStore->bind($this->filterName(), $versionTwo, $this->layout(), $winnerB);

        try {
            $firstStore->bind($this->filterName(), $versionTwo, $this->layout(), $winnerA);
            self::fail('Expected opposite-order second binder to lose.');
        } catch (GenerationContractConflict) {
            $descriptor = $secondStore->read($this->filterName(), $versionTwo);
            self::assertNotNull($descriptor);
            self::assertTrue($winnerB->equals($descriptor->semanticContract()));
        }
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    private function layout(): BloomLayout
    {
        return BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
    }

    private function positions(): BitPositions
    {
        return BitPositions::forLayout($this->layout(), [1, 4, 7]);
    }

    private function contract(
        string $normalization,
        string $authoritativeSet,
        string $consistency,
    ): GenerationSemanticContract {
        return new GenerationSemanticContract(
            normalizationFingerprint: NormalizationFingerprint::fromString(
                'sha256:'.str_repeat($normalization, 64),
            ),
            authoritativeSetFingerprint: AuthoritativeSetFingerprint::fromString(
                'sha256:'.str_repeat($authoritativeSet, 64),
            ),
            consistencyFingerprint: ConsistencyFingerprint::fromString(
                'sha256:'.str_repeat($consistency, 64),
            ),
        );
    }
}
