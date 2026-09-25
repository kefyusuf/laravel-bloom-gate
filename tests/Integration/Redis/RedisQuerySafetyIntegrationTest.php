<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use Kefyusuf\BloomGate\Drivers\Redis\RedisAuthorizedProbe;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Support\Redis\CountingRedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('redis')]
final class RedisQuerySafetyIntegrationTest extends TestCase
{
    private CountingRedisStructuredCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisFilterControlStore $control;

    private RedisGenerationContractStore $contracts;

    private RedisBloomDriver $bloom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new CountingRedisStructuredCommandExecutor(
            new RespRedisCommandExecutor(
                (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
                (int) (getenv('REDIS_PORT') ?: 6379),
            ),
        );
        $this->keyspace = RedisKeyspace::fromPrefix(
            'lbgquery'.bin2hex((string) random_bytes(8)),
        );
        $this->control = new RedisFilterControlStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            codec: new RedisControlStateCodec,
        );
        $this->contracts = new RedisGenerationContractStore(
            $this->executor,
            $this->keyspace,
        );
        $this->bloom = new RedisBloomDriver(
            $this->executor,
            $this->keyspace,
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->executor->evaluate(
                "return redis.call('DEL', KEYS[1], KEYS[2], KEYS[3])",
                [
                    $this->keyspace->stateKey($this->filterName()),
                    $this->keyspace->metaKey($this->filterName(), FilterVersion::fromInt(1)),
                    $this->keyspace->bitmapKey($this->filterName(), FilterVersion::fromInt(1)),
                ],
                [],
            );
        } catch (Throwable) {
            // Cleanup must not mask the primary failure.
        }

        parent::tearDown();
    }

    public function test_active_snapshot_is_bounded_with_five_thousand_retained_generations(): void
    {
        $generations = [];

        for ($version = 1; $version <= 5000; $version++) {
            $generations[] = new GenerationControlState(
                FilterVersion::fromInt($version),
                $version === 5000
                    ? LifecycleState::Active
                    : LifecycleState::Retired,
                $version === 5000
                    ? HealthState::Healthy
                    : HealthState::Unavailable,
            );
        }

        $this->control->compareAndSwap(
            $this->filterName(),
            new FilterControlState(
                filterName: $this->filterName(),
                revision: FilterStateRevision::fromInt(1),
                lastAllocatedVersion: FilterVersion::fromInt(5000),
                activeVersion: FilterVersion::fromInt(5000),
                candidateVersion: null,
                generations: $generations,
            ),
            null,
        );

        self::assertInstanceOf(ActiveGenerationSnapshotReader::class, $this->control);

        $before = $this->executor->structuredCalls();
        $snapshot = $this->control->readActive($this->filterName());

        self::assertNotNull($snapshot);
        self::assertSame(1, $snapshot->revision()->value());
        self::assertSame(5000, $snapshot->activeVersion()->value());
        self::assertSame($before + 1, $this->executor->structuredCalls());
    }

    public function test_final_authorized_decision_is_one_eval_after_descriptor_preparation(): void
    {
        $this->seedActiveGeneration();
        $resolver = new QuerySafetyDescriptorResolver(
            $this->control,
            $this->contracts,
        );
        $resolution = $resolver->resolve(
            $this->filterName(),
            $this->contract(),
        );
        $descriptor = $resolution->descriptor();

        self::assertNotNull($descriptor);

        $probe = new RedisAuthorizedProbe(
            executor: $this->executor,
            keyspace: $this->keyspace,
            trustedNegativeProfile: RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE,
        );
        $before = $this->executor->structuredCalls();

        $absent = $probe->probe($descriptor, $this->positions());

        self::assertSame(Membership::DefinitelyAbsent, $absent->membership());
        self::assertSame($before + 1, $this->executor->structuredCalls());

        $this->bloom->addMany(
            $this->filterName(),
            FilterVersion::fromInt(1),
            [$this->positions()],
        );

        $beforeMaybe = $this->executor->structuredCalls();
        $maybe = $probe->probe($descriptor, $this->positions());

        self::assertSame(Membership::MaybePresent, $maybe->membership());
        self::assertSame($beforeMaybe + 1, $this->executor->structuredCalls());
    }

    public function test_revision_drift_bypasses_atomic_probe(): void
    {
        $this->seedActiveGeneration();

        $descriptor = (new QuerySafetyDescriptorResolver(
            $this->control,
            $this->contracts,
        ))->resolve($this->filterName(), $this->contract())->descriptor();

        self::assertNotNull($descriptor);

        $this->control->compareAndSwap(
            $this->filterName(),
            new FilterControlState(
                filterName: $this->filterName(),
                revision: FilterStateRevision::fromInt(2),
                lastAllocatedVersion: FilterVersion::fromInt(1),
                activeVersion: FilterVersion::fromInt(1),
                candidateVersion: null,
                generations: [
                    new GenerationControlState(
                        FilterVersion::fromInt(1),
                        LifecycleState::Active,
                        HealthState::Healthy,
                    ),
                ],
            ),
            FilterStateRevision::fromInt(1),
        );

        $result = $this->probe()->probe($descriptor, $this->positions());

        self::assertSame(Membership::Bypassed, $result->membership());
        self::assertSame('control_state_changed', $result->bypassReason()?->code());
    }

    public function test_active_version_drift_is_classified_before_generation_storage(): void
    {
        $this->seedActiveGeneration();

        $descriptor = new QuerySafetyDescriptor(
            filterName: $this->filterName(),
            revision: FilterStateRevision::fromInt(1),
            activeVersion: FilterVersion::fromInt(2),
            layout: $this->layout(),
            semanticContract: $this->contract(),
        );

        $result = $this->probe()->probe($descriptor, $this->positions());

        self::assertSame(Membership::Bypassed, $result->membership());
        self::assertSame('control_state_changed', $result->bypassReason()?->code());
    }

    public function test_wrong_generation_meta_type_is_never_absent(): void
    {
        $version = FilterVersion::fromInt(1);

        $this->control->compareAndSwap(
            $this->filterName(),
            new FilterControlState(
                filterName: $this->filterName(),
                revision: FilterStateRevision::fromInt(1),
                lastAllocatedVersion: $version,
                activeVersion: $version,
                candidateVersion: null,
                generations: [
                    new GenerationControlState(
                        $version,
                        LifecycleState::Active,
                        HealthState::Healthy,
                    ),
                ],
            ),
            null,
        );

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('SET', KEYS[1], 'wrong-type'); return 1",
            [$this->keyspace->metaKey($this->filterName(), $version)],
            [],
        ));

        $result = $this->probe()->probe($this->descriptor(), $this->positions());

        self::assertSame(Membership::Bypassed, $result->membership());
        self::assertSame('generation_storage_corrupt', $result->bypassReason()?->code());
    }

    public function test_unbound_and_every_semantic_mismatch_are_never_absent(): void
    {
        $this->seedActiveControlAndStorage(bind: false);

        $unboundDescriptor = $this->descriptor();

        $unbound = $this->probe()->probe($unboundDescriptor, $this->positions());

        self::assertSame(Membership::Bypassed, $unbound->membership());
        self::assertSame('generation_contract_unbound', $unbound->bypassReason()?->code());

        $this->contracts->bind(
            $this->filterName(),
            FilterVersion::fromInt(1),
            $this->layout(),
            $this->contract(),
        );

        foreach ([
            [
                new GenerationSemanticContract(
                    NormalizationFingerprint::fromString('sha256:'.str_repeat('d', 64)),
                    $this->contract()->authoritativeSetFingerprint(),
                    $this->contract()->consistencyFingerprint(),
                ),
                'normalization_mismatch',
            ],
            [
                new GenerationSemanticContract(
                    $this->contract()->normalizationFingerprint(),
                    AuthoritativeSetFingerprint::fromString('sha256:'.str_repeat('d', 64)),
                    $this->contract()->consistencyFingerprint(),
                ),
                'authoritative_set_mismatch',
            ],
            [
                new GenerationSemanticContract(
                    $this->contract()->normalizationFingerprint(),
                    $this->contract()->authoritativeSetFingerprint(),
                    ConsistencyFingerprint::fromString('sha256:'.str_repeat('d', 64)),
                ),
                'consistency_mismatch',
            ],
        ] as [$contract, $reason]) {
            $descriptor = new QuerySafetyDescriptor(
                filterName: $this->filterName(),
                revision: FilterStateRevision::fromInt(1),
                activeVersion: FilterVersion::fromInt(1),
                layout: $this->layout(),
                semanticContract: $contract,
            );

            $result = $this->probe()->probe($descriptor, $this->positions());

            self::assertSame(Membership::Bypassed, $result->membership());
            self::assertSame($reason, $result->bypassReason()?->code());
        }
    }

    public function test_missing_generation_storage_is_never_absent(): void
    {
        $version = FilterVersion::fromInt(1);

        $this->control->compareAndSwap(
            $this->filterName(),
            new FilterControlState(
                filterName: $this->filterName(),
                revision: FilterStateRevision::fromInt(1),
                lastAllocatedVersion: $version,
                activeVersion: $version,
                candidateVersion: null,
                generations: [
                    new GenerationControlState(
                        $version,
                        LifecycleState::Active,
                        HealthState::Healthy,
                    ),
                ],
            ),
            null,
        );

        $result = $this->probe()->probe($this->descriptor(), $this->positions());

        self::assertSame(Membership::Bypassed, $result->membership());
        self::assertSame('generation_storage_unavailable', $result->bypassReason()?->code());
    }

    public function test_layout_drift_and_malformed_marker_are_never_absent(): void
    {
        $this->seedActiveGeneration();

        $differentLayout = BloomLayout::create(
            64,
            3,
            ProbeAlgorithm::Sha256DoubleHashV1,
        );
        $layoutDrift = new QuerySafetyDescriptor(
            filterName: $this->filterName(),
            revision: FilterStateRevision::fromInt(1),
            activeVersion: FilterVersion::fromInt(1),
            layout: $differentLayout,
            semanticContract: $this->contract(),
        );

        $layoutResult = $this->probe()->probe(
            $layoutDrift,
            BitPositions::forLayout($differentLayout, [1, 4, 7]),
        );

        self::assertSame(Membership::Bypassed, $layoutResult->membership());
        self::assertSame('generation_storage_corrupt', $layoutResult->bypassReason()?->code());

        self::assertSame(1, $this->executor->evaluate(
            "redis.call('HSET', KEYS[1], 'managed_bitmap_written', '2'); return 1",
            [$this->keyspace->metaKey($this->filterName(), FilterVersion::fromInt(1))],
            [],
        ));

        $markerResult = $this->probe()->probe($this->descriptor(), $this->positions());

        self::assertSame(Membership::Bypassed, $markerResult->membership());
        self::assertSame('generation_storage_corrupt', $markerResult->bypassReason()?->code());
    }

    public function test_written_marker_with_missing_bitmap_is_never_absent(): void
    {
        $this->seedActiveGeneration();

        $descriptor = (new QuerySafetyDescriptorResolver(
            $this->control,
            $this->contracts,
        ))->resolve($this->filterName(), $this->contract())->descriptor();

        self::assertNotNull($descriptor);

        $this->bloom->addMany(
            $this->filterName(),
            FilterVersion::fromInt(1),
            [$this->positions()],
        );

        self::assertSame(1, $this->executor->evaluate(
            "return redis.call('DEL', KEYS[1])",
            [$this->keyspace->bitmapKey($this->filterName(), FilterVersion::fromInt(1))],
            [],
        ));

        $result = $this->probe()->probe($descriptor, $this->positions());

        self::assertSame(Membership::Bypassed, $result->membership());
        self::assertSame('generation_storage_corrupt', $result->bypassReason()?->code());
    }

    private function seedActiveGeneration(): void
    {
        $this->seedActiveControlAndStorage(bind: true);
    }

    private function seedActiveControlAndStorage(bool $bind): void
    {
        $version = FilterVersion::fromInt(1);

        $this->control->compareAndSwap(
            $this->filterName(),
            new FilterControlState(
                filterName: $this->filterName(),
                revision: FilterStateRevision::fromInt(1),
                lastAllocatedVersion: $version,
                activeVersion: $version,
                candidateVersion: null,
                generations: [
                    new GenerationControlState(
                        $version,
                        LifecycleState::Active,
                        HealthState::Healthy,
                    ),
                ],
            ),
            null,
        );
        $this->bloom->provision($this->filterName(), $version, $this->layout());

        if ($bind) {
            $this->contracts->bind(
                $this->filterName(),
                $version,
                $this->layout(),
                $this->contract(),
            );
        }
    }

    private function probe(): RedisAuthorizedProbe
    {
        return new RedisAuthorizedProbe(
            executor: $this->executor,
            keyspace: $this->keyspace,
            trustedNegativeProfile: RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE,
        );
    }

    private function descriptor(): QuerySafetyDescriptor
    {
        return new QuerySafetyDescriptor(
            filterName: $this->filterName(),
            revision: FilterStateRevision::fromInt(1),
            activeVersion: FilterVersion::fromInt(1),
            layout: $this->layout(),
            semanticContract: $this->contract(),
        );
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    private function layout(): BloomLayout
    {
        return BloomLayout::create(
            32,
            3,
            ProbeAlgorithm::Sha256DoubleHashV1,
        );
    }

    private function contract(): GenerationSemanticContract
    {
        return new GenerationSemanticContract(
            NormalizationFingerprint::fromString('sha256:'.str_repeat('a', 64)),
            AuthoritativeSetFingerprint::fromString('sha256:'.str_repeat('b', 64)),
            ConsistencyFingerprint::fromString('sha256:'.str_repeat('c', 64)),
        );
    }

    private function positions(): BitPositions
    {
        return BitPositions::forLayout($this->layout(), [1, 4, 7]);
    }
}
