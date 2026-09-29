<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

use Kefyusuf\BloomGate\Application\CandidateDiscarder;
use Kefyusuf\BloomGate\Application\LegacyMutationFilterControlStore;
use Kefyusuf\BloomGate\Application\LegacyMutationGuard;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Lifecycle\ActiveGenerationDeactivator;
use Kefyusuf\BloomGate\Lifecycle\CandidateAllocator;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;
use PHPUnit\Framework\TestCase;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu05RuntimeCoordinationRequirement;

final class LegacyMutationLifecyclePathsTest extends TestCase
{
    public function test_runtime_requirement_fences_candidate_allocation(): void
    {
        [, $store] = $this->store();

        $this->expectException(CoordinationFenced::class);

        (new CandidateAllocator($store))->allocate(
            FilterName::fromString('users.email'),
        );
    }

    public function test_runtime_requirement_fences_candidate_lifecycle_transition(): void
    {
        [$raw, $store] = $this->store();
        $name = FilterName::fromString('users.email');
        $this->seed($raw, $this->candidateState($name, LifecycleState::Configured));

        $this->expectException(CoordinationFenced::class);

        (new GenerationLifecycleTransitioner(
            $store,
            new LifecycleTransitionPolicy,
        ))->transition(
            $name,
            FilterVersion::fromInt(2),
            LifecycleState::Building,
        );
    }

    public function test_runtime_requirement_fences_generation_health_mutation(): void
    {
        [$raw, $store] = $this->store();
        $name = FilterName::fromString('users.email');
        $this->seed($raw, $this->candidateState($name, LifecycleState::Shadow));

        $this->expectException(CoordinationFenced::class);

        (new GenerationHealthUpdater($store))->update(
            $name,
            FilterVersion::fromInt(2),
            HealthState::Stale,
        );
    }

    public function test_runtime_requirement_fences_candidate_discard(): void
    {
        [$raw, $store] = $this->store();
        $name = FilterName::fromString('users.email');
        $this->seed($raw, $this->candidateState($name, LifecycleState::Shadow));
        $transitions = new GenerationLifecycleTransitioner(
            $store,
            new LifecycleTransitionPolicy,
        );

        $this->expectException(CoordinationFenced::class);

        (new CandidateDiscarder(
            control: $store,
            transitions: $transitions,
        ))->discard($name);
    }

    public function test_runtime_requirement_fences_candidate_promotion(): void
    {
        [$raw, $store] = $this->store();
        $name = FilterName::fromString('users.email');
        $this->seed($raw, $this->candidateState($name, LifecycleState::Verified));

        $this->expectException(CoordinationFenced::class);

        (new CandidatePromoter($store))->promote(
            $name,
            FilterVersion::fromInt(2),
        );
    }

    public function test_runtime_requirement_fences_active_deactivation(): void
    {
        [$raw, $store] = $this->store();
        $name = FilterName::fromString('users.email');
        $this->seed($raw, $this->activeOnlyState($name));

        $this->expectException(CoordinationFenced::class);

        (new ActiveGenerationDeactivator($store))->deactivate($name);
    }

    /**
     * @return array{MemoryFilterControlStore, LegacyMutationFilterControlStore}
     */
    private function store(): array
    {
        $domain = new MemoryCoordinationDomain;
        $raw = new MemoryFilterControlStore($domain);
        $runtime = new Wu05RuntimeCoordinationRequirement(true);
        $guard = new LegacyMutationGuard(
            coordination: new MemoryCoordinatedLifecycleStore($domain),
            runtime: $runtime,
        );

        return [
            $raw,
            new LegacyMutationFilterControlStore(
                store: $raw,
                snapshots: $raw,
                guard: $guard,
            ),
        ];
    }

    private function seed(
        MemoryFilterControlStore $store,
        FilterControlState $state,
    ): void {
        $targetRevision = $state->revision()->value();

        for ($revision = 1; $revision <= $targetRevision; $revision++) {
            $snapshot = new FilterControlState(
                filterName: $state->filterName(),
                revision: FilterStateRevision::fromInt($revision),
                lastAllocatedVersion: $state->lastAllocatedVersion(),
                activeVersion: $state->activeVersion(),
                candidateVersion: $state->candidateVersion(),
                generations: $state->generations(),
            );

            $store->compareAndSwap(
                $state->filterName(),
                $snapshot,
                $revision === 1
                    ? null
                    : FilterStateRevision::fromInt($revision - 1),
            );
        }
    }

    private function candidateState(
        FilterName $name,
        LifecycleState $candidateLifecycle,
    ): FilterControlState {
        $active = FilterVersion::fromInt(1);
        $candidate = FilterVersion::fromInt(2);

        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(3),
            lastAllocatedVersion: $candidate,
            activeVersion: $active,
            candidateVersion: $candidate,
            generations: [
                new GenerationControlState(
                    version: $active,
                    lifecycle: LifecycleState::Active,
                    health: HealthState::Healthy,
                ),
                new GenerationControlState(
                    version: $candidate,
                    lifecycle: $candidateLifecycle,
                    health: HealthState::Healthy,
                ),
            ],
        );
    }

    private function activeOnlyState(
        FilterName $name,
    ): FilterControlState {
        $active = FilterVersion::fromInt(1);

        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(2),
            lastAllocatedVersion: $active,
            activeVersion: $active,
            candidateVersion: null,
            generations: [
                new GenerationControlState(
                    version: $active,
                    lifecycle: LifecycleState::Active,
                    health: HealthState::Healthy,
                ),
            ],
        );
    }
}
