<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

use Kefyusuf\BloomGate\Application\CoordinationDoctor;
use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\ProductionSafetyCheckStatus;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu05RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryCoordinationFixtureState;
use PHPUnit\Framework\TestCase;

final class CoordinationDoctorTest extends TestCase
{
    public function test_runtime_requires_coordination_without_durable_adoption_is_a_failure(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');

        $report = $this->doctor($state, required: true)->inspect($name);

        self::assertSame(
            ProductionSafetyCheckStatus::Fail,
            $report->status('coordination.runtime'),
        );
        self::assertSame(
            ProductionSafetyCheckStatus::NotEnabled,
            $report->status('coordination.ownership'),
        );
    }

    public function test_durable_adoption_with_runtime_legacy_mode_is_a_failure(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putControl($name, $this->activeControl($name));
        $state->putCoordination($name, true, $this->steadySync());

        $report = $this->doctor($state, required: false)->inspect($name);

        self::assertSame(
            ProductionSafetyCheckStatus::Fail,
            $report->status('coordination.runtime'),
        );
        self::assertSame(
            ProductionSafetyCheckStatus::Pass,
            $report->status('coordination.ownership'),
        );
    }

    public function test_adoption_pending_and_impossible_relation_fail_without_repair(): void
    {
        $name = FilterName::fromString('users.email');

        $pendingState = new MemoryCoordinationFixtureState;
        $pendingState->putControl($name, $this->activeControl($name));
        $pendingState->putCoordination($name, true, null);

        $pending = $this->doctor($pendingState, required: true)->inspect($name);

        self::assertSame(
            ProductionSafetyCheckStatus::Fail,
            $pending->status('coordination.state'),
        );
        self::assertNull(
            $pendingState->lifecycle->read($name)->synchronization(),
        );

        $invalidState = new MemoryCoordinationFixtureState;
        $invalidState->putControl($name, $this->activeControl($name));
        $invalidState->putCoordination(
            $name,
            true,
            new SynchronizationState(
                revision: SynchronizationRevision::fromInt(1),
                phase: SynchronizationPhase::Steady,
                currentEpoch: SynchronizationEpoch::fromInt(1),
                currentTargets: SynchronizationTargetSet::fromVersions([]),
                candidateVersion: null,
                drainingEpoch: null,
            ),
        );

        $invalid = $this->doctor($invalidState, required: true)->inspect($name);

        self::assertSame(
            ProductionSafetyCheckStatus::Fail,
            $invalid->status('coordination.state'),
        );
        self::assertSame(
            1,
            $invalidState->lifecycle
                ->read($name)
                ->synchronization()
                ?->revision()
                ->value(),
        );
    }

    public function test_blocked_drain_is_warn_and_never_repaired(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putControl($name, $this->candidateControl($name));
        $state->putCoordination(
            $name,
            true,
            new SynchronizationState(
                revision: SynchronizationRevision::fromInt(2),
                phase: SynchronizationPhase::DrainingPreReconcile,
                currentEpoch: SynchronizationEpoch::fromInt(2),
                currentTargets: SynchronizationTargetSet::fromVersions([
                    FilterVersion::fromInt(1),
                    FilterVersion::fromInt(2),
                ]),
                candidateVersion: FilterVersion::fromInt(2),
                drainingEpoch: SynchronizationEpoch::fromInt(1),
            ),
        );
        $state->setActiveWriterCount(
            $name,
            SynchronizationEpoch::fromInt(1),
            2,
        );

        $report = $this->doctor($state, required: true)->inspect($name);

        self::assertSame(
            ProductionSafetyCheckStatus::Warn,
            $report->status('coordination.drain'),
        );
        self::assertSame(
            2,
            $state->writer->activeWriterCount(
                $name,
                SynchronizationEpoch::fromInt(1),
            ),
        );
    }

    private function doctor(
        MemoryCoordinationFixtureState $state,
        bool $required,
    ): CoordinationDoctor {
        return new CoordinationDoctor(
            new CoordinationStatusReader(
                lifecycle: $state->lifecycle,
                writers: $state->writer,
                leases: $state->writer,
                runtime: new Wu05RuntimeCoordinationRequirement($required),
            ),
        );
    }

    private function activeControl(FilterName $name): FilterControlState
    {
        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(1),
            lastAllocatedVersion: FilterVersion::fromInt(1),
            activeVersion: FilterVersion::fromInt(1),
            candidateVersion: null,
            generations: [
                new GenerationControlState(
                    version: FilterVersion::fromInt(1),
                    lifecycle: LifecycleState::Active,
                    health: HealthState::Healthy,
                ),
            ],
        );
    }

    private function candidateControl(FilterName $name): FilterControlState
    {
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
                    lifecycle: LifecycleState::Shadow,
                    health: HealthState::Healthy,
                ),
            ],
        );
    }

    private function steadySync(): SynchronizationState
    {
        return new SynchronizationState(
            revision: SynchronizationRevision::fromInt(1),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt(1),
            currentTargets: SynchronizationTargetSet::fromVersions([
                FilterVersion::fromInt(1),
            ]),
            candidateVersion: null,
            drainingEpoch: null,
        );
    }
}
