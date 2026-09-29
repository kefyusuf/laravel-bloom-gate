<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

use Kefyusuf\BloomGate\Application\LegacyMutationGuard;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu05RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryCoordinationFixtureState;
use PHPUnit\Framework\TestCase;

final class LegacyMutationGuardTest extends TestCase
{
    public function test_never_adopted_filter_remains_eligible_when_runtime_is_uncoordinated(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $guard = new LegacyMutationGuard(
            coordination: $state->lifecycle,
            runtime: $this->runtimeRequirement(false),
        );

        $guard->assertAllowed(FilterName::fromString('users.email'));

        self::addToAssertionCount(1);
    }

    public function test_persisted_ownership_fences_legacy_mutation_even_when_runtime_is_uncoordinated(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putCoordination(
            $name,
            true,
            $this->syncState(),
        );
        $guard = new LegacyMutationGuard(
            coordination: $state->lifecycle,
            runtime: $this->runtimeRequirement(false),
        );

        $this->expectException(CoordinationFenced::class);

        $guard->assertAllowed($name);
    }

    public function test_runtime_coordinated_requirement_fences_missing_owner_as_recovery_required(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $guard = new LegacyMutationGuard(
            coordination: $state->lifecycle,
            runtime: $this->runtimeRequirement(true),
        );

        $this->expectException(CoordinationFenced::class);

        $guard->assertAllowed(FilterName::fromString('users.email'));
    }

    public function test_contradictory_persisted_coordination_remains_corruption_not_runtime_downgrade(): void
    {
        $state = new MemoryCoordinationFixtureState;
        $name = FilterName::fromString('users.email');
        $state->putCoordination(
            $name,
            false,
            $this->syncState(),
        );
        $guard = new LegacyMutationGuard(
            coordination: $state->lifecycle,
            runtime: $this->runtimeRequirement(false),
        );

        $this->expectException(CoordinationStateCorrupt::class);

        $guard->assertAllowed($name);
    }

    private function runtimeRequirement(
        bool $required,
    ): Wu05RuntimeCoordinationRequirement {
        return new Wu05RuntimeCoordinationRequirement($required);
    }

    private function syncState(): SynchronizationState
    {
        return new SynchronizationState(
            revision: SynchronizationRevision::fromInt(1),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt(1),
            currentTargets: SynchronizationTargetSet::fromVersions([]),
            candidateVersion: null,
            drainingEpoch: null,
        );
    }
}
