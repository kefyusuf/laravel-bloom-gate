<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
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
use Kefyusuf\BloomGate\Tests\Contract\Support\CoordinatedLifecycleStoreContractFixture;
use PHPUnit\Framework\TestCase;

abstract class CoordinatedLifecycleStoreContractTestCase extends TestCase
{
    abstract protected function newFixture(): CoordinatedLifecycleStoreContractFixture;

    public function test_never_adopted_read_preserves_control_and_absent_coordination(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();
        $control = $this->controlState(1);

        $fixture->putControl($name, $control);

        $snapshot = $fixture->store()->read($name);

        self::assertFalse($snapshot->ownershipClaimed());
        self::assertNull($snapshot->synchronization());
        self::assertSame(1, $snapshot->control()?->revision()->value());
    }

    public function test_claim_ownership_is_one_way_and_requires_sync_absence(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();

        $claimed = $fixture->store()->claimOwnership($name, null);

        self::assertTrue($claimed->ownershipClaimed());
        self::assertNull($claimed->synchronization());

        try {
            $fixture->store()->claimOwnership($name, null);
            self::fail('Expected an already-claimed ownership marker to fence a second claim.');
        } catch (CoordinationFenced) {
            self::assertTrue($fixture->store()->read($name)->ownershipClaimed());
        }

        $syncPresent = $this->newFixture();
        $syncPresent->putCoordination(
            $name,
            false,
            $this->synchronizationState(1),
        );

        $this->expectException(CoordinationStateCorrupt::class);

        $syncPresent->store()->claimOwnership($name, null);
    }

    public function test_claim_ownership_conflicts_on_stale_control_relation(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();

        $fixture->putControl($name, $this->controlState(1));

        try {
            $fixture->store()->claimOwnership(
                $name,
                FilterStateRevision::fromInt(2),
            );

            self::fail('Expected stale adoption control relation to conflict.');
        } catch (CoordinationWriteConflict) {
            self::assertFalse($fixture->store()->read($name)->ownershipClaimed());
        }
    }

    public function test_coordinated_control_cas_is_guarded_by_exact_sync_revision(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();

        $fixture->putControl($name, $this->controlState(1));
        $fixture->putCoordination($name, true, $this->synchronizationState(7));

        try {
            $fixture->store()->compareAndSwapControl(
                $name,
                $this->controlState(2),
                FilterStateRevision::fromInt(1),
                SynchronizationRevision::fromInt(6),
            );

            self::fail('Expected stale sync revision to fence coordinated control CAS.');
        } catch (CoordinationWriteConflict) {
            self::assertSame(
                1,
                $fixture->store()->read($name)->control()?->revision()->value(),
            );
        }
    }

    public function test_coordinated_control_cas_treats_null_control_revision_as_required_absence(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();

        $fixture->putControl($name, $this->controlState(1));
        $fixture->putCoordination($name, true, $this->synchronizationState(1));

        $this->expectException(CoordinationWriteConflict::class);

        $fixture->store()->compareAndSwapControl(
            $name,
            $this->controlState(2),
            null,
            SynchronizationRevision::fromInt(1),
        );
    }

    public function test_coordinated_sync_cas_treats_null_control_revision_as_required_absence(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();

        $fixture->putControl($name, $this->controlState(1));
        $fixture->putCoordination($name, true, null);

        $this->expectException(CoordinationWriteConflict::class);

        $fixture->store()->compareAndSwapSynchronization(
            $name,
            $this->synchronizationState(1),
            null,
            null,
        );
    }

    public function test_coordinated_sync_cas_treats_null_sync_revision_as_required_absence(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();

        $fixture->putCoordination($name, true, $this->synchronizationState(1));

        $this->expectException(CoordinationWriteConflict::class);

        $fixture->store()->compareAndSwapSynchronization(
            $name,
            $this->synchronizationState(2),
            null,
            null,
        );
    }

    public function test_owner_sync_contradiction_fails_closed(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();

        $fixture->putCoordination($name, false, $this->synchronizationState(1));

        $this->expectException(CoordinationStateCorrupt::class);

        $fixture->store()->read($name);
    }

    public function test_staging_state_is_not_current_correctness_state(): void
    {
        $fixture = $this->newFixture();
        $name = $this->filterName();

        $fixture->putStagingSynchronization($name, $this->synchronizationState(1));

        $snapshot = $fixture->store()->read($name);

        self::assertFalse($snapshot->ownershipClaimed());
        self::assertNull($snapshot->synchronization());
    }

    protected function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    protected function controlState(int $revision): FilterControlState
    {
        $name = $this->filterName();
        $version = FilterVersion::fromInt(1);

        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt($revision),
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

    protected function synchronizationState(
        int $revision,
        int $epoch = 1,
    ): SynchronizationState {
        return new SynchronizationState(
            revision: SynchronizationRevision::fromInt($revision),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt($epoch),
            currentTargets: SynchronizationTargetSet::fromVersions([
                FilterVersion::fromInt(1),
            ]),
            candidateVersion: null,
            drainingEpoch: null,
        );
    }
}
