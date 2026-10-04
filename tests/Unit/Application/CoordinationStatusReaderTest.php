<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Application\CoordinationStatusState;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Contracts\WriterLeaseInspector;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
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
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordinationStatusReaderTest extends TestCase
{
    public function test_invalid_runtime_configuration_is_distinct_from_unavailable_storage(): void
    {
        $lifecycle = $this->createMock(CoordinatedLifecycleStore::class);
        $lifecycle->expects(self::never())->method('read');
        $requirement = $this->createMock(RuntimeCoordinationRequirement::class);
        $requirement->method('requiresCoordinatedV1')->willThrowException(
            new InvalidConfiguration('Unsupported coordination mode.'),
        );
        $reader = new CoordinationStatusReader($lifecycle, $this->createMock(WriterSynchronizationStore::class), $requirement);
        $status = $reader->read(FilterName::fromString('users.email'));

        self::assertSame(CoordinationStatusState::Invalid, $status->state());
        self::assertSame('invalid_configuration', $status->issue());
        self::assertNull($status->runtimeCoordinationRequired());
        self::assertNull($status->synchronization());
        self::assertNull($status->drainingActiveWriterCount());
    }

    public function test_never_adopted_filter_is_reported_without_mutation(): void
    {
        $name = FilterName::fromString('users.email');
        $lifecycle = $this->createMock(CoordinatedLifecycleStore::class);
        $lifecycle->expects(self::once())->method('read')->with($name)
            ->willReturn(new CoordinatedLifecycleSnapshot(false, null, null));
        $lifecycle->expects(self::never())->method('claimOwnership');
        $lifecycle->expects(self::never())->method('compareAndSwapControl');
        $lifecycle->expects(self::never())->method('compareAndSwapSynchronization');
        $writers = $this->createMock(WriterSynchronizationStore::class);
        $writers->expects(self::never())->method('activeWriterCount');
        $writers->expects(self::never())->method('acquire');
        $writers->expects(self::never())->method('markPrepared');
        $writers->expects(self::never())->method('release');
        $requirement = $this->createMock(RuntimeCoordinationRequirement::class);
        $requirement->method('requiresCoordinatedV1')->willReturn(false);

        $status = (new CoordinationStatusReader($lifecycle, $writers, $requirement))->read($name);

        self::assertSame(CoordinationStatusState::Unadopted, $status->state());
        self::assertNull($status->synchronization());
        self::assertNull($status->drainingActiveWriterCount());
        self::assertNull($status->issue());
    }

    /** @return iterable<string, array{SynchronizationPhase, ?LifecycleState, list<int>, ?int, ?int, int, ?string}> */
    public static function phases(): iterable
    {
        yield 'steady' => [SynchronizationPhase::Steady, null, [1], null, null, 0, null];
        yield 'unpublished candidate' => [SynchronizationPhase::Steady, LifecycleState::Building, [1], null, null, 0, null];
        yield 'pre reconcile drain' => [SynchronizationPhase::DrainingPreReconcile, LifecycleState::Shadow, [1, 2], 2, 1, 3, 'draining_writers'];
        yield 'drain ready' => [SynchronizationPhase::DrainingPreReconcile, LifecycleState::Shadow, [1, 2], 2, 1, 0, 'rebuild_in_progress'];
        yield 'reconcile' => [SynchronizationPhase::Reconciling, LifecycleState::Shadow, [1, 2], 2, null, 0, 'rebuild_in_progress'];
        yield 'verification persisted' => [SynchronizationPhase::Reconciling, LifecycleState::Verified, [1, 2], 2, null, 0, 'rebuild_in_progress'];
        yield 'ready' => [SynchronizationPhase::ReadyToPromote, LifecycleState::Verified, [1, 2], 2, null, 0, 'rebuild_in_progress'];
        yield 'promotion persisted' => [SynchronizationPhase::ReadyToPromote, LifecycleState::Active, [1, 2], 2, null, 0, 'promotion_recovery'];
        yield 'post promotion' => [SynchronizationPhase::DrainingPostPromotion, LifecycleState::Active, [2], null, 1, 2, 'draining_writers'];
        yield 'post promotion ready' => [SynchronizationPhase::DrainingPostPromotion, LifecycleState::Active, [2], null, 1, 0, 'rebuild_in_progress'];
        yield 'abort intent' => [SynchronizationPhase::AbortRequested, LifecycleState::Shadow, [1, 2], 2, 1, 3, 'abort_requested'];
        yield 'abort drain' => [SynchronizationPhase::DrainingAbort, LifecycleState::Shadow, [1], 2, 1, 3, 'abort_draining'];
        yield 'retirement persisted' => [SynchronizationPhase::DrainingAbort, LifecycleState::Retired, [1], 2, 1, 0, 'abort_draining'];
    }

    /** @param list<int> $targets */
    #[DataProvider('phases')]
    public function test_reports_resumable_phases_without_writes(
        SynchronizationPhase $phase, ?LifecycleState $candidate, array $targets,
        ?int $syncCandidate, ?int $drain, int $count, ?string $issue,
    ): void {
        $sync = $this->sync($phase, $targets, $syncCandidate, $drain);
        $status = $this->reader(new CoordinatedLifecycleSnapshot(true, $this->control($candidate), $sync), true, $count)
            ->read(FilterName::fromString('users.email'));

        self::assertSame(CoordinationStatusState::Adopted, $status->state());
        self::assertSame($sync, $status->synchronization());
        self::assertSame($drain === null ? null : $count, $status->drainingActiveWriterCount());
        self::assertSame($issue, $status->issue());
    }

    public function test_missing_sync_is_pending_and_config_disagreement_is_invalid(): void
    {
        $name = FilterName::fromString('users.email');
        $pending = $this->reader(new CoordinatedLifecycleSnapshot(true, null, null), true)->read($name);
        self::assertSame(CoordinationStatusState::AdoptionPending, $pending->state());
        self::assertSame('adoption_pending', $pending->issue());
        $missingOwner = $this->reader(new CoordinatedLifecycleSnapshot(false, null, null), true)->read($name);
        self::assertSame(CoordinationStatusState::Invalid, $missingOwner->state());
        self::assertSame('coordination_required', $missingOwner->issue());
        $mismatch = $this->reader(new CoordinatedLifecycleSnapshot(true, null, null), false)->read($name);
        self::assertSame('runtime_coordination_not_required', $mismatch->issue());
    }

    public function test_impossible_target_and_drain_relations_are_invalid(): void
    {
        foreach ([
            $this->sync(SynchronizationPhase::Steady, [2], null, null),
            $this->sync(SynchronizationPhase::DrainingPreReconcile, [1, 2], 2, null),
            $this->sync(SynchronizationPhase::DrainingPreReconcile, [1, 2], 2, 2),
            $this->sync(SynchronizationPhase::Reconciling, [1], 2, null),
            $this->sync(SynchronizationPhase::ReadyToPromote, [1, 2], 2, null),
        ] as $sync) {
            $status = $this->reader(new CoordinatedLifecycleSnapshot(true, $this->control(LifecycleState::Shadow), $sync), true)
                ->read(FilterName::fromString('users.email'));
            self::assertSame(CoordinationStatusState::Invalid, $status->state());
            self::assertSame('invalid_relation', $status->issue());
        }
    }

    public function test_corruption_and_unavailability_never_report_adopted(): void
    {
        foreach ([new CoordinationStateCorrupt('malformed'), new CoordinationStoreOperationFailed('offline')] as $failure) {
            $lifecycle = $this->createMock(CoordinatedLifecycleStore::class);
            $lifecycle->method('read')->willThrowException($failure);
            $requirement = $this->createMock(RuntimeCoordinationRequirement::class);
            $requirement->method('requiresCoordinatedV1')->willReturn(true);
            $reader = new CoordinationStatusReader($lifecycle, $this->createMock(WriterSynchronizationStore::class), $requirement);
            $status = $reader->read(FilterName::fromString('users.email'));
            self::assertSame($failure instanceof CoordinationStateCorrupt ? CoordinationStatusState::Invalid : CoordinationStatusState::Unavailable, $status->state());
        }
    }

    private function reader(CoordinatedLifecycleSnapshot $snapshot, bool $required, int $count = 0): CoordinationStatusReader
    {
        $lifecycle = $this->createMock(CoordinatedLifecycleStore::class);
        $lifecycle->method('read')->willReturn($snapshot);
        foreach (['claimOwnership', 'compareAndSwapControl', 'compareAndSwapSynchronization'] as $method) {
            $lifecycle->expects(self::never())->method($method);
        }
        $writers = $this->createMock(WriterSynchronizationStore::class);
        $writers->method('activeWriterCount')->willReturn($count);
        foreach (['acquire', 'markPrepared', 'release'] as $method) {
            $writers->expects(self::never())->method($method);
        }
        $requirement = $this->createMock(RuntimeCoordinationRequirement::class);
        $requirement->method('requiresCoordinatedV1')->willReturn($required);

        return new CoordinationStatusReader($lifecycle, $writers, $requirement);
    }

    public function test_unavailable_count_keeps_sync_evidence_without_inventing_zero(): void
    {
        $sync = $this->sync(SynchronizationPhase::DrainingPreReconcile, [1, 2], 2, 1);
        $lifecycle = $this->createMock(CoordinatedLifecycleStore::class);
        $lifecycle->method('read')->willReturn(new CoordinatedLifecycleSnapshot(true, $this->control(LifecycleState::Shadow), $sync));
        $writers = $this->createMock(WriterSynchronizationStore::class);
        $writers->method('activeWriterCount')->willThrowException(new CoordinationStoreOperationFailed('offline'));
        $requirement = $this->createMock(RuntimeCoordinationRequirement::class);
        $requirement->method('requiresCoordinatedV1')->willReturn(true);
        $status = (new CoordinationStatusReader($lifecycle, $writers, $requirement))->read(FilterName::fromString('users.email'));
        self::assertSame(CoordinationStatusState::Unavailable, $status->state());
        self::assertSame($sync, $status->synchronization());
        self::assertNull($status->drainingActiveWriterCount());
    }

    public function test_retired_abort_candidate_with_remaining_writers_is_invalid(): void
    {
        $sync = $this->sync(SynchronizationPhase::DrainingAbort, [1], 2, 1);
        $status = $this->reader(new CoordinatedLifecycleSnapshot(true, $this->control(LifecycleState::Retired), $sync), true, 2)
            ->read(FilterName::fromString('users.email'));
        self::assertSame(CoordinationStatusState::Invalid, $status->state());
        self::assertSame('invalid_relation', $status->issue());
    }

    public function test_lease_enumeration_is_opt_in_and_not_used_as_the_drain_count(): void
    {
        $name = FilterName::fromString('users.email');
        $sync = $this->sync(SynchronizationPhase::DrainingPreReconcile, [1, 2], 2, 1);
        $lifecycle = $this->createMock(CoordinatedLifecycleStore::class);
        $lifecycle->method('read')->willReturn(new CoordinatedLifecycleSnapshot(true, $this->control(LifecycleState::Shadow), $sync));
        $writers = $this->createMock(WriterSynchronizationStore::class);
        $writers->method('activeWriterCount')->willReturn(3);
        $requirement = $this->createMock(RuntimeCoordinationRequirement::class);
        $requirement->method('requiresCoordinatedV1')->willReturn(true);
        $lease = new WriterLease(WriterLeaseToken::fromString(str_repeat('a', 32)), WriterLeaseState::Prepared,
            SynchronizationEpoch::fromInt(1), SynchronizationTargetSet::fromVersions([FilterVersion::fromInt(1)]));
        $inspector = $this->createMock(WriterLeaseInspector::class);
        $inspector->expects(self::once())->method('readActiveLeases')->with($name)->willReturn([$lease]);
        $reader = new CoordinationStatusReader($lifecycle, $writers, $requirement, $inspector);
        self::assertSame([], $reader->read($name)->leases());
        $status = $reader->read($name, includeLeases: true);
        self::assertSame([$lease], $status->leases());
        self::assertSame(3, $status->drainingActiveWriterCount());
        self::assertSame('draining_writers', $status->issue());
    }

    /** @param list<int> $targets */
    private function sync(SynchronizationPhase $phase, array $targets, ?int $candidate, ?int $drain): SynchronizationState
    {
        return new SynchronizationState(
            SynchronizationRevision::fromInt(3), $phase, SynchronizationEpoch::fromInt(2),
            SynchronizationTargetSet::fromVersions(array_map(FilterVersion::fromInt(...), $targets)),
            $candidate === null ? null : FilterVersion::fromInt($candidate),
            $drain === null ? null : SynchronizationEpoch::fromInt($drain),
        );
    }

    private function control(?LifecycleState $candidate): FilterControlState
    {
        $promoted = $candidate === LifecycleState::Active;
        $generations = [new GenerationControlState(FilterVersion::fromInt(1), $promoted ? LifecycleState::Retired : LifecycleState::Active, HealthState::Healthy)];
        if ($candidate !== null) {
            $generations[] = new GenerationControlState(FilterVersion::fromInt(2), $candidate, HealthState::Healthy);
        }

        return new FilterControlState(
            FilterName::fromString('users.email'), FilterStateRevision::fromInt(4),
            FilterVersion::fromInt($candidate === null ? 1 : 2), FilterVersion::fromInt($promoted ? 2 : 1),
            $candidate === null || $promoted || $candidate === LifecycleState::Retired ? null : FilterVersion::fromInt(2),
            $generations,
        );
    }
}
