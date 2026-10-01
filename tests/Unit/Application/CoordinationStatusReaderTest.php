<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

use Kefyusuf\BloomGate\Application\CoordinationBlocker;
use Kefyusuf\BloomGate\Application\CoordinationOwnership;
use Kefyusuf\BloomGate\Application\CoordinationRecovery;
use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
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
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu05RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryCoordinationFixtureState;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class CoordinationStatusReaderTest extends TestCase
{
    public function test_unadopted_state_distinguishes_runtime_agreement_from_missing_required_adoption(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');

        $ordinary = $this->reader($state, required: false)->read($name);
        $required = $this->reader($state, required: true)->read($name);

        self::assertSame(CoordinationOwnership::Unadopted, $ordinary->ownership());
        self::assertSame(CoordinationBlocker::None, $ordinary->blocker());
        self::assertSame(CoordinationRecovery::None, $ordinary->recovery());

        self::assertSame(CoordinationOwnership::Unadopted, $required->ownership());
        self::assertSame(
            CoordinationBlocker::RuntimeConfigurationMismatch,
            $required->blocker(),
        );
    }

    public function test_owner_without_sync_is_adoption_pending_and_never_treated_as_unadopted(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putControl($name, $this->activeControl($name));
        $state->putCoordination($name, true, null);

        $status = $this->reader($state, required: true)->read($name);

        self::assertSame(CoordinationOwnership::AdoptionPending, $status->ownership());
        self::assertSame(CoordinationBlocker::AdoptionPending, $status->blocker());
        self::assertNull($status->synchronizationRevision());
    }

    public function test_adopted_steady_status_exposes_persisted_coordination_fields(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $control = $this->activeControl($name);
        $sync = $this->sync(
            phase: SynchronizationPhase::Steady,
            epoch: 4,
            targets: [1],
        );
        $state->putControl($name, $control);
        $state->putCoordination($name, true, $sync);

        $status = $this->reader($state, required: true)->read($name);

        self::assertSame(CoordinationOwnership::Adopted, $status->ownership());
        self::assertSame(4, $status->currentEpoch()?->value());
        self::assertSame(1, $status->synchronizationRevision()?->value());
        self::assertSame(SynchronizationPhase::Steady, $status->phase());
        self::assertSame([1], $this->versions($status->currentTargets()));
        self::assertNull($status->candidateVersion());
        self::assertNull($status->drainingEpoch());
        self::assertNull($status->drainingActiveWriterCount());
        self::assertSame(CoordinationBlocker::None, $status->blocker());
        self::assertSame(CoordinationRecovery::None, $status->recovery());
    }

    public function test_impossible_control_sync_relation_is_invalid_without_mutation(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putControl($name, $this->activeControl($name));
        $state->putCoordination(
            $name,
            true,
            $this->sync(
                phase: SynchronizationPhase::Steady,
                epoch: 2,
                targets: [],
            ),
        );

        $status = $this->reader($state, required: true)->read($name);

        self::assertSame(CoordinationOwnership::Invalid, $status->ownership());
        self::assertSame(CoordinationBlocker::InvalidState, $status->blocker());
        self::assertSame(CoordinationRecovery::None, $status->recovery());

        $snapshot = $state->lifecycle->read($name);
        self::assertSame(1, $snapshot->control()?->revision()->value());
        self::assertSame(1, $snapshot->synchronization()?->revision()->value());
    }

    public function test_blocked_drain_uses_persisted_epoch_count_and_reports_it(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putControl(
            $name,
            $this->candidateControl(
                $name,
                LifecycleState::Shadow,
            ),
        );
        $state->putCoordination(
            $name,
            true,
            $this->sync(
                phase: SynchronizationPhase::DrainingPreReconcile,
                epoch: 2,
                targets: [1, 2],
                candidate: 2,
                draining: 1,
            ),
        );
        $state->setActiveWriterCount(
            $name,
            SynchronizationEpoch::fromInt(1),
            3,
        );

        $status = $this->reader($state, required: true)->read($name);

        self::assertSame(CoordinationOwnership::Adopted, $status->ownership());
        self::assertSame(3, $status->drainingActiveWriterCount());
        self::assertSame(CoordinationBlocker::DrainingWriters, $status->blocker());
    }

    public function test_post_promotion_shape_is_classified_as_recovery_not_invalid(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putControl($name, $this->promotedControl($name));
        $state->putCoordination(
            $name,
            true,
            $this->sync(
                phase: SynchronizationPhase::ReadyToPromote,
                epoch: 2,
                targets: [1, 2],
                candidate: 2,
            ),
        );

        $status = $this->reader($state, required: true)->read($name);

        self::assertSame(CoordinationOwnership::Adopted, $status->ownership());
        self::assertSame(
            CoordinationRecovery::PromotionSynchronizationPending,
            $status->recovery(),
        );
    }

    public function test_abort_requested_and_draining_abort_are_distinct_recovery_states(): void
    {
        $name = FilterName::fromString('users.email');

        $requestedState = new MemoryCoordinationFixtureState;
        $requestedState->putControl(
            $name,
            $this->candidateControl($name, LifecycleState::Shadow),
        );
        $requestedState->putCoordination(
            $name,
            true,
            $this->sync(
                phase: SynchronizationPhase::AbortRequested,
                epoch: 2,
                targets: [1, 2],
                candidate: 2,
                draining: 1,
            ),
        );
        $requestedState->setActiveWriterCount(
            $name,
            SynchronizationEpoch::fromInt(1),
            1,
        );

        $requested = $this->reader($requestedState, required: true)->read($name);

        self::assertSame(CoordinationRecovery::AbortRequested, $requested->recovery());
        self::assertSame(CoordinationBlocker::DrainingWriters, $requested->blocker());

        $drainingState = new MemoryCoordinationFixtureState;
        $drainingState->putControl(
            $name,
            $this->candidateControl($name, LifecycleState::Shadow),
        );
        $drainingState->putCoordination(
            $name,
            true,
            $this->sync(
                phase: SynchronizationPhase::DrainingAbort,
                epoch: 3,
                targets: [1],
                candidate: 2,
                draining: 2,
            ),
        );

        $draining = $this->reader($drainingState, required: true)->read($name);

        self::assertSame(CoordinationRecovery::AbortDraining, $draining->recovery());
    }

    public function test_active_lease_diagnostics_include_acquired_and_prepared_but_not_released(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putControl($name, $this->activeControl($name));
        $state->putCoordination(
            $name,
            true,
            $this->sync(
                phase: SynchronizationPhase::Steady,
                epoch: 1,
                targets: [1],
            ),
        );

        $acquiredToken = $this->token('a');
        $preparedToken = $this->token('b');
        $releasedToken = $this->token('c');

        $state->writer->acquire($name, $acquiredToken);
        $state->writer->acquire($name, $preparedToken);
        $state->writer->markPrepared($name, $preparedToken);
        $state->writer->acquire($name, $releasedToken);
        $state->writer->release($name, $releasedToken);

        $status = $this->reader($state, required: true)->read(
            $name,
            includeLeases: true,
        );

        self::assertTrue($status->leasesIncluded());
        self::assertCount(2, $status->activeLeases());
        self::assertSame(
            [WriterLeaseState::Acquired, WriterLeaseState::Prepared],
            array_map(
                static fn ($lease) => $lease->state(),
                $status->activeLeases(),
            ),
        );
        self::assertSame(
            [$acquiredToken->value(), $preparedToken->value()],
            array_map(
                static fn ($lease): string => $lease->token()->value(),
                $status->activeLeases(),
            ),
        );
    }

    public function test_malformed_coordination_is_reported_invalid_instead_of_throwing(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putControl($name, $this->activeControl($name));
        $state->putCoordination($name, true, null);

        $property = new ReflectionProperty(
            MemoryCoordinationDomain::class,
            'synchronizations',
        );
        /** @var array<string, mixed> $values */
        $values = $property->getValue($state->domain);
        $values[$name->value()] = 'malformed';
        $property->setValue($state->domain, $values);

        $status = $this->reader($state, required: true)->read($name);

        self::assertSame(CoordinationOwnership::Invalid, $status->ownership());
        self::assertSame(CoordinationBlocker::InvalidState, $status->blocker());
    }

    public function test_diagnostics_store_unavailability_is_non_authorizing_and_explicit(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');

        $reader = new CoordinationStatusReader(
            lifecycle: new Wu10UnavailableLifecycleStore,
            writers: $state->writer,
            leases: $state->writer,
            runtime: new Wu05RuntimeCoordinationRequirement(true),
        );

        $status = $reader->read($name);

        self::assertSame(CoordinationOwnership::Invalid, $status->ownership());
        self::assertSame(
            CoordinationBlocker::DiagnosticsUnavailable,
            $status->blocker(),
        );
    }

    private function reader(
        MemoryCoordinationFixtureState $state,
        bool $required,
    ): CoordinationStatusReader {
        return new CoordinationStatusReader(
            lifecycle: $state->lifecycle,
            writers: $state->writer,
            leases: $state->writer,
            runtime: new Wu05RuntimeCoordinationRequirement($required),
        );
    }

    private function activeControl(FilterName $name): FilterControlState
    {
        $version = FilterVersion::fromInt(1);

        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(1),
            lastAllocatedVersion: $version,
            activeVersion: $version,
            candidateVersion: null,
            generations: [
                new GenerationControlState(
                    version: $version,
                    lifecycle: LifecycleState::Active,
                    health: HealthState::Healthy,
                ),
            ],
        );
    }

    private function candidateControl(
        FilterName $name,
        LifecycleState $candidateLifecycle,
    ): FilterControlState {
        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(2),
            lastAllocatedVersion: FilterVersion::fromInt(2),
            activeVersion: FilterVersion::fromInt(1),
            candidateVersion: FilterVersion::fromInt(2),
            generations: [
                new GenerationControlState(
                    version: FilterVersion::fromInt(1),
                    lifecycle: LifecycleState::Active,
                    health: HealthState::Healthy,
                ),
                new GenerationControlState(
                    version: FilterVersion::fromInt(2),
                    lifecycle: $candidateLifecycle,
                    health: HealthState::Healthy,
                ),
            ],
        );
    }

    private function promotedControl(FilterName $name): FilterControlState
    {
        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(3),
            lastAllocatedVersion: FilterVersion::fromInt(2),
            activeVersion: FilterVersion::fromInt(2),
            candidateVersion: null,
            generations: [
                new GenerationControlState(
                    version: FilterVersion::fromInt(1),
                    lifecycle: LifecycleState::Retired,
                    health: HealthState::Healthy,
                ),
                new GenerationControlState(
                    version: FilterVersion::fromInt(2),
                    lifecycle: LifecycleState::Active,
                    health: HealthState::Healthy,
                ),
            ],
        );
    }

    /**
     * @param  list<int>  $targets
     */
    private function sync(
        SynchronizationPhase $phase,
        int $epoch,
        array $targets,
        ?int $candidate = null,
        ?int $draining = null,
    ): SynchronizationState {
        return new SynchronizationState(
            revision: SynchronizationRevision::fromInt(1),
            phase: $phase,
            currentEpoch: SynchronizationEpoch::fromInt($epoch),
            currentTargets: SynchronizationTargetSet::fromVersions(
                array_map(
                    static fn (int $version): FilterVersion => FilterVersion::fromInt($version),
                    $targets,
                ),
            ),
            candidateVersion: $candidate === null
                ? null
                : FilterVersion::fromInt($candidate),
            drainingEpoch: $draining === null
                ? null
                : SynchronizationEpoch::fromInt($draining),
        );
    }

    /**
     * @return list<int>
     */
    private function versions(?SynchronizationTargetSet $targets): array
    {
        if ($targets === null) {
            return [];
        }

        return array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $targets->versions(),
        );
    }

    private function token(string $character): WriterLeaseToken
    {
        return WriterLeaseToken::fromString(str_repeat($character, 32));
    }
}

final class Wu10UnavailableLifecycleStore implements CoordinatedLifecycleStore
{
    public function read(FilterName $name): CoordinatedLifecycleSnapshot
    {
        throw new CoordinationStoreOperationFailed('WU-10 simulated diagnostics outage.');
    }

    public function claimOwnership(
        FilterName $name,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        throw new LogicException('WU-10 diagnostics fixture is read-only.');
    }

    public function compareAndSwapControl(
        FilterName $name,
        FilterControlState $nextControl,
        ?FilterStateRevision $expectedControlRevision,
        SynchronizationRevision $expectedSyncRevision,
    ): CoordinatedLifecycleSnapshot {
        throw new LogicException('WU-10 diagnostics fixture is read-only.');
    }

    public function compareAndSwapSynchronization(
        FilterName $name,
        SynchronizationState $nextSynchronization,
        ?SynchronizationRevision $expectedSyncRevision,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        throw new LogicException('WU-10 diagnostics fixture is read-only.');
    }
}
