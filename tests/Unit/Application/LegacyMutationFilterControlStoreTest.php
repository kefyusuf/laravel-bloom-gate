<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

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
use PHPUnit\Framework\TestCase;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu05RuntimeCoordinationRequirement;

final class LegacyMutationFilterControlStoreTest extends TestCase
{
    public function test_read_only_control_and_active_snapshot_inspection_remain_available_when_runtime_requires_coordination(): void
    {
        [$raw, $store] = $this->store(runtimeRequired: true);
        $name = FilterName::fromString('users.email');

        $raw->compareAndSwap(
            $name,
            $this->activeState($name, 1),
            null,
        );

        self::assertSame(1, $store->read($name)?->revision()->value());
        self::assertSame(1, $store->readActive($name)?->activeVersion()->value());
    }

    public function test_runtime_requirement_blocks_control_cas_without_mutating_raw_state(): void
    {
        [$raw, $store] = $this->store(runtimeRequired: true);
        $name = FilterName::fromString('users.email');
        $raw->compareAndSwap(
            $name,
            $this->activeState($name, 1),
            null,
        );

        try {
            $store->compareAndSwap(
                $name,
                $this->activeState($name, 2),
                FilterStateRevision::fromInt(1),
            );
            self::fail('Expected runtime coordinated requirement to fence legacy control CAS.');
        } catch (CoordinationFenced) {
            self::assertSame(1, $raw->read($name)?->revision()->value());
        }
    }

    /**
     * @return array{MemoryFilterControlStore, LegacyMutationFilterControlStore}
     */
    private function store(
        bool $runtimeRequired,
    ): array {
        $domain = new MemoryCoordinationDomain;
        $raw = new MemoryFilterControlStore($domain);
        $requirement = new Wu05RuntimeCoordinationRequirement(
            $runtimeRequired,
        );
        $guard = new LegacyMutationGuard(
            coordination: new MemoryCoordinatedLifecycleStore($domain),
            runtime: $requirement,
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

    private function activeState(
        FilterName $name,
        int $revision,
    ): FilterControlState {
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
}
