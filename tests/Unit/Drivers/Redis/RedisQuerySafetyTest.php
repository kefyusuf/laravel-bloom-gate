<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Drivers\Redis;

use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use Kefyusuf\BloomGate\Drivers\Redis\RedisAuthorizedProbe;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Drivers\Redis\RedisQuerySafetyScripts;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingRedisStructuredCommandExecutor;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RedisQuerySafetyTest extends TestCase
{
    public function test_control_store_additively_implements_bounded_active_snapshot_reader(): void
    {
        $executor = new RecordingRedisStructuredCommandExecutor([
            [
                RedisQuerySafetyScripts::STATUS_ACTIVE,
                '7',
                '2',
                'active',
                'healthy',
            ],
        ]);
        $store = $this->controlStore($executor);

        self::assertInstanceOf(ActiveGenerationSnapshotReader::class, $store);

        $snapshot = $store->readActive($this->filterName());

        self::assertNotNull($snapshot);
        self::assertSame(7, $snapshot->revision()->value());
        self::assertSame(2, $snapshot->activeVersion()->value());
        self::assertSame(LifecycleState::Active, $snapshot->lifecycle());
        self::assertSame(HealthState::Healthy, $snapshot->health());
        self::assertSame([
            [
                'script' => RedisQuerySafetyScripts::readActive(),
                'keys' => ['lbg:{users.email}:state'],
                'arguments' => [],
            ],
        ], $executor->structuredCalls());
    }

    public function test_bounded_active_snapshot_returns_null_without_active_version(): void
    {
        $executor = new RecordingRedisStructuredCommandExecutor([
            [RedisQuerySafetyScripts::STATUS_NO_ACTIVE],
        ]);

        self::assertNull($this->controlStore($executor)->readActive($this->filterName()));
    }

    public function test_bounded_active_snapshot_maps_corrupt_and_backend_failures(): void
    {
        $corrupt = new RecordingRedisStructuredCommandExecutor([
            [RedisQuerySafetyScripts::STATUS_CORRUPT],
        ]);

        $this->expectException(FilterControlStateCorrupt::class);
        $this->controlStore($corrupt)->readActive($this->filterName());
    }

    public function test_bounded_active_snapshot_wraps_redis_transport_failure(): void
    {
        $failure = new RedisCommandFailed('transport');
        $executor = new RecordingRedisStructuredCommandExecutor([$failure]);

        try {
            $this->controlStore($executor)->readActive($this->filterName());

            self::fail('Expected bounded Redis control read to fail.');
        } catch (FilterControlStoreOperationFailed $actual) {
            self::assertSame($failure, $actual->getPrevious());
        }
    }

    public function test_active_snapshot_script_is_bounded_read_only_and_never_hgetall(): void
    {
        $script = RedisQuerySafetyScripts::readActive();

        self::assertStringContainsString("'HMGET'", $script);
        self::assertStringContainsString("'active_version'", $script);
        self::assertStringContainsString("'g:' .. activeVersion .. ':lifecycle'", $script);
        self::assertStringContainsString("'g:' .. activeVersion .. ':health'", $script);
        self::assertStringNotContainsString('HGETALL', $script);
        self::assertStringNotContainsString("'HSET'", $script);
        self::assertStringNotContainsString("'DEL'", $script);
        self::assertStringNotContainsString("'RENAME'", $script);
        self::assertStringNotContainsString("'EXPIRE'", $script);
        self::assertStringNotContainsString('KEYS[2]', $script);
    }

    public function test_missing_profile_bypasses_without_contacting_redis(): void
    {
        $executor = new RecordingRedisStructuredCommandExecutor([]);
        $probe = $this->probe($executor, null);

        $result = $probe->probe($this->descriptor(), $this->positions());

        self::assertSame(Membership::Bypassed, $result->membership());
        self::assertSame('backend_profile_unasserted', $result->bypassReason()?->code());
        self::assertSame([], $executor->structuredCalls());
    }

    public function test_unsupported_profile_cannot_authorize_negative(): void
    {
        $executor = new RecordingRedisStructuredCommandExecutor([]);
        $probe = $this->probe($executor, 'unsupported-profile');

        $result = $probe->probe($this->descriptor(), $this->positions());

        self::assertSame(Membership::Bypassed, $result->membership());
        self::assertSame('backend_profile_unasserted', $result->bypassReason()?->code());
        self::assertSame([], $executor->structuredCalls());
    }

    public function test_supported_profile_executes_one_atomic_probe_with_explicit_keys(): void
    {
        $executor = new RecordingRedisStructuredCommandExecutor([
            [RedisQuerySafetyScripts::RESULT_ABSENT],
        ]);
        $probe = $this->probe(
            $executor,
            RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE,
        );

        $result = $probe->probe($this->descriptor(), $this->positions());

        self::assertSame(Membership::DefinitelyAbsent, $result->membership());
        self::assertNull($result->bypassReason());
        self::assertSame([
            [
                'script' => RedisQuerySafetyScripts::authorizedProbe(),
                'keys' => [
                    'lbg:{users.email}:state',
                    'lbg:{users.email}:v:2:meta',
                    'lbg:{users.email}:v:2:bf',
                ],
                'arguments' => [
                    '7',
                    '2',
                    'redis-bitmap-v1',
                    '32',
                    '3',
                    'sha256-double-hash-v1',
                    'sha256:'.str_repeat('a', 64),
                    'sha256:'.str_repeat('b', 64),
                    'sha256:'.str_repeat('c', 64),
                    '1',
                    '4',
                    '7',
                ],
            ],
        ], $executor->structuredCalls());
    }

    public function test_authorized_probe_maps_maybe_and_every_stable_bypass_reason(): void
    {
        $maybe = new RecordingRedisStructuredCommandExecutor([
            [RedisQuerySafetyScripts::RESULT_MAYBE],
        ]);

        self::assertSame(
            Membership::MaybePresent,
            $this->probe(
                $maybe,
                RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE,
            )->probe($this->descriptor(), $this->positions())->membership(),
        );

        foreach ([
            'control_state_changed',
            'operation_failed',
            'generation_storage_unavailable',
            'generation_storage_corrupt',
            'generation_contract_unbound',
            'normalization_mismatch',
            'authoritative_set_mismatch',
            'consistency_mismatch',
        ] as $reason) {
            $executor = new RecordingRedisStructuredCommandExecutor([
                [RedisQuerySafetyScripts::RESULT_BYPASS, $reason],
            ]);

            $result = $this->probe(
                $executor,
                RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE,
            )->probe($this->descriptor(), $this->positions());

            self::assertSame(Membership::Bypassed, $result->membership());
            self::assertSame($reason, $result->bypassReason()?->code());
        }
    }

    public function test_authorized_probe_maps_transport_failure_to_backend_unavailable(): void
    {
        $failure = new RedisCommandFailed('transport');
        $executor = new RecordingRedisStructuredCommandExecutor([$failure]);

        $result = $this->probe(
            $executor,
            RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE,
        )->probe($this->descriptor(), $this->positions());

        self::assertSame(Membership::Bypassed, $result->membership());
        self::assertSame('backend_unavailable', $result->bypassReason()?->code());
    }

    public function test_authorized_probe_rejects_programming_layout_mismatch_before_redis(): void
    {
        $executor = new RecordingRedisStructuredCommandExecutor([]);
        $probe = $this->probe(
            $executor,
            RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE,
        );
        $different = BloomLayout::create(
            64,
            3,
            ProbeAlgorithm::Sha256DoubleHashV1,
        );

        $this->expectException(BloomLayoutMismatch::class);

        $probe->probe(
            $this->descriptor(),
            BitPositions::forLayout($different, [1, 4, 7]),
        );
    }

    public function test_authorized_probe_rejects_unexpected_protocol_result(): void
    {
        $executor = new RecordingRedisStructuredCommandExecutor([
            [RedisQuerySafetyScripts::RESULT_PROTOCOL_ERROR],
        ]);

        $this->expectException(UnexpectedValueException::class);

        $this->probe(
            $executor,
            RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE,
        )->probe($this->descriptor(), $this->positions());
    }

    public function test_authorized_probe_script_is_read_only_bounded_and_uses_only_declared_keys(): void
    {
        $script = RedisQuerySafetyScripts::authorizedProbe();

        self::assertStringContainsString('KEYS[1]', $script);
        self::assertStringContainsString('KEYS[2]', $script);
        self::assertStringContainsString('KEYS[3]', $script);
        self::assertStringContainsString("'HMGET'", $script);
        self::assertStringContainsString("'GETBIT'", $script);
        self::assertStringNotContainsString('HGETALL', $script);
        self::assertStringNotContainsString("'HSET'", $script);
        self::assertStringNotContainsString("'SETBIT'", $script);
        self::assertStringNotContainsString("'DEL'", $script);
        self::assertStringNotContainsString("'RENAME'", $script);
        self::assertStringNotContainsString("'EXPIRE'", $script);
        self::assertStringNotContainsString("'INFO'", $script);
        self::assertStringNotContainsString("'ROLE'", $script);
        self::assertStringNotContainsString("'CONFIG'", $script);
    }

    private function controlStore(
        RecordingRedisStructuredCommandExecutor $executor,
    ): RedisFilterControlStore {
        return new RedisFilterControlStore(
            executor: $executor,
            keyspace: RedisKeyspace::fromPrefix('lbg'),
            codec: new RedisControlStateCodec,
        );
    }

    private function probe(
        RecordingRedisStructuredCommandExecutor $executor,
        ?string $profile,
    ): RedisAuthorizedProbe {
        return new RedisAuthorizedProbe(
            executor: $executor,
            keyspace: RedisKeyspace::fromPrefix('lbg'),
            trustedNegativeProfile: $profile,
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

    private function descriptor(): QuerySafetyDescriptor
    {
        return new QuerySafetyDescriptor(
            filterName: $this->filterName(),
            revision: FilterStateRevision::fromInt(7),
            activeVersion: FilterVersion::fromInt(2),
            layout: $this->layout(),
            semanticContract: $this->contract(),
        );
    }

    private function positions(): BitPositions
    {
        return BitPositions::forLayout($this->layout(), [1, 4, 7]);
    }
}
