<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class FilterControlStoreContractTestCase extends TestCase
{
    abstract protected function makeStore(): FilterControlStore;

    public function test_missing_state_returns_null(): void
    {
        self::assertNull($this->makeStore()->read($this->filterName()));
    }

    public function test_create_requires_a_null_expected_revision(): void
    {
        $store = $this->makeStore();

        $this->expectException(FilterControlWriteConflict::class);

        $store->compareAndSwap(
            $this->filterName(),
            $this->state(revision: 1),
            FilterStateRevision::fromInt(1),
        );
    }

    public function test_create_persists_revision_one(): void
    {
        $store = $this->makeStore();
        $expected = $this->state(revision: 1);

        $store->compareAndSwap($this->filterName(), $expected, null);

        $this->assertStateEquivalent($expected, $store->read($this->filterName()));
    }

    public function test_create_rejects_a_non_initial_revision_without_mutation(): void
    {
        $store = $this->makeStore();

        try {
            $store->compareAndSwap(
                $this->filterName(),
                $this->state(revision: 2),
                null,
            );

            self::fail('Expected a non-initial create revision to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertNull($store->read($this->filterName()));
        }
    }

    public function test_second_create_conflicts_and_preserves_existing_state(): void
    {
        $store = $this->makeStore();
        $existing = $this->state(revision: 1);
        $store->compareAndSwap($this->filterName(), $existing, null);

        try {
            $store->compareAndSwap(
                $this->filterName(),
                $this->state(revision: 1, health: HealthState::Degraded),
                null,
            );

            self::fail('Expected a second create to conflict.');
        } catch (FilterControlWriteConflict) {
            $this->assertStateEquivalent($existing, $store->read($this->filterName()));
        }
    }

    public function test_update_requires_the_exact_current_revision_and_advances_once(): void
    {
        $store = $this->makeStore();
        $initial = $this->state(revision: 1);
        $next = $this->state(
            revision: 2,
            lifecycle: LifecycleState::Building,
            health: HealthState::Degraded,
        );

        $store->compareAndSwap($this->filterName(), $initial, null);
        $store->compareAndSwap(
            $this->filterName(),
            $next,
            FilterStateRevision::fromInt(1),
        );

        $this->assertStateEquivalent($next, $store->read($this->filterName()));
    }

    public function test_stale_expected_revision_conflicts_without_overwriting_the_winner(): void
    {
        $store = $this->makeStore();
        $revisionOne = $this->state(revision: 1);
        $winner = $this->state(revision: 2, lifecycle: LifecycleState::Building);
        $loser = $this->state(
            revision: 3,
            lifecycle: LifecycleState::Shadow,
            health: HealthState::Degraded,
        );

        $store->compareAndSwap($this->filterName(), $revisionOne, null);
        $store->compareAndSwap(
            $this->filterName(),
            $winner,
            FilterStateRevision::fromInt(1),
        );

        try {
            $store->compareAndSwap(
                $this->filterName(),
                $loser,
                FilterStateRevision::fromInt(1),
            );

            self::fail('Expected a stale compare-and-swap to conflict.');
        } catch (FilterControlWriteConflict) {
            $this->assertStateEquivalent($winner, $store->read($this->filterName()));
        }
    }

    public function test_update_rejects_a_skipped_revision_without_mutation(): void
    {
        $store = $this->makeStore();
        $existing = $this->state(revision: 1);
        $store->compareAndSwap($this->filterName(), $existing, null);

        try {
            $store->compareAndSwap(
                $this->filterName(),
                $this->state(revision: 3, lifecycle: LifecycleState::Building),
                FilterStateRevision::fromInt(1),
            );

            self::fail('Expected a skipped state revision to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertStateEquivalent($existing, $store->read($this->filterName()));
        }
    }

    public function test_target_filter_name_must_match_the_snapshot_before_mutation(): void
    {
        $store = $this->makeStore();
        $target = $this->filterName();
        $existing = $this->state(revision: 1, name: $target);
        $other = FilterName::fromString('Users.Email');

        $store->compareAndSwap($target, $existing, null);

        try {
            $store->compareAndSwap(
                $target,
                $this->state(revision: 2, name: $other),
                FilterStateRevision::fromInt(1),
            );

            self::fail('Expected a target/snapshot filter identity mismatch to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertStateEquivalent($existing, $store->read($target));
            self::assertNull($store->read($other));
        }
    }

    public function test_read_returns_the_complete_stored_snapshot_semantics(): void
    {
        $store = $this->makeStore();
        $name = FilterName::fromString('products.sku');
        $active = FilterVersion::fromInt(1);
        $candidate = FilterVersion::fromInt(3);

        $expected = new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(1),
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
                    lifecycle: LifecycleState::Shadow,
                    health: HealthState::Degraded,
                ),
            ],
        );

        $store->compareAndSwap($name, $expected, null);

        $this->assertStateEquivalent($expected, $store->read($name));
    }

    public function test_failure_taxonomy_keeps_conflict_operation_failure_and_corruption_distinct(): void
    {
        $conflict = new FilterControlWriteConflict;
        $operationFailure = new FilterControlStoreOperationFailed;
        $corruption = new FilterControlStateCorrupt;

        self::assertInstanceOf(RuntimeException::class, $conflict);
        self::assertInstanceOf(RuntimeException::class, $operationFailure);
        self::assertInstanceOf(RuntimeException::class, $corruption);
        self::assertNotSame($conflict::class, $operationFailure::class);
        self::assertNotSame($conflict::class, $corruption::class);
        self::assertNotSame($operationFailure::class, $corruption::class);
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    private function state(
        int $revision,
        ?FilterName $name = null,
        LifecycleState $lifecycle = LifecycleState::Configured,
        HealthState $health = HealthState::Unavailable,
    ): FilterControlState {
        $filterName = $name ?? $this->filterName();
        $version = FilterVersion::fromInt(1);

        return new FilterControlState(
            filterName: $filterName,
            revision: FilterStateRevision::fromInt($revision),
            lastAllocatedVersion: $version,
            activeVersion: null,
            candidateVersion: $version,
            generations: [
                new GenerationControlState(
                    version: $version,
                    lifecycle: $lifecycle,
                    health: $health,
                ),
            ],
        );
    }

    private function assertStateEquivalent(
        FilterControlState $expected,
        ?FilterControlState $actual,
    ): void {
        self::assertNotNull($actual);
        self::assertSame($expected->filterName()->value(), $actual->filterName()->value());
        self::assertSame($expected->revision()->value(), $actual->revision()->value());
        self::assertSame(
            $expected->lastAllocatedVersion()->value(),
            $actual->lastAllocatedVersion()->value(),
        );
        self::assertSame(
            $expected->activeVersion()?->value(),
            $actual->activeVersion()?->value(),
        );
        self::assertSame(
            $expected->candidateVersion()?->value(),
            $actual->candidateVersion()?->value(),
        );

        $expectedGenerations = $expected->generations();
        $actualGenerations = $actual->generations();

        self::assertCount(count($expectedGenerations), $actualGenerations);

        foreach ($expectedGenerations as $index => $expectedGeneration) {
            $actualGeneration = $actualGenerations[$index];

            self::assertSame(
                $expectedGeneration->version()->value(),
                $actualGeneration->version()->value(),
            );
            self::assertSame(
                $expectedGeneration->lifecycle(),
                $actualGeneration->lifecycle(),
            );
            self::assertSame(
                $expectedGeneration->health(),
                $actualGeneration->health(),
            );
        }
    }
}
