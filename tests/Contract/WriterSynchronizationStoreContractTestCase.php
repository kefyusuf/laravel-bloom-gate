<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Contracts\Exception\WriterLeaseReleased;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use PHPUnit\Framework\TestCase;

abstract class WriterSynchronizationStoreContractTestCase extends TestCase
{
    abstract protected function newFixture(): WriterSynchronizationStoreContractFixture;

    public function test_acquire_binds_current_epoch_and_targets_atomically(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('0');

        $lease = $fixture->store()->acquire($name, $token);

        self::assertSame(WriterLeaseState::Acquired, $lease->state());
        self::assertSame(1, $lease->epoch()->value());
        self::assertSame([1, 2], $this->targetValues($lease->targets()));
        self::assertSame(
            1,
            $fixture->store()->activeWriterCount(
                $name,
                SynchronizationEpoch::fromInt(1),
            ),
        );
    }

    public function test_acquire_retry_preserves_original_binding_and_count(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('1');

        $first = $fixture->store()->acquire($name, $token);

        $fixture->putCoordination(
            $name,
            true,
            $this->synchronizationState(
                revision: 2,
                epoch: 2,
                targets: [2],
            ),
        );

        $retry = $fixture->store()->acquire($name, $token);

        self::assertSame($first->epoch()->value(), $retry->epoch()->value());
        self::assertSame(
            $this->targetValues($first->targets()),
            $this->targetValues($retry->targets()),
        );
        self::assertSame(
            1,
            $fixture->store()->activeWriterCount(
                $name,
                SynchronizationEpoch::fromInt(1),
            ),
        );
        self::assertSame(
            0,
            $fixture->store()->activeWriterCount(
                $name,
                SynchronizationEpoch::fromInt(2),
            ),
        );
    }

    public function test_mark_prepared_is_idempotent_and_count_neutral(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('2');

        $fixture->store()->acquire($name, $token);
        $prepared = $fixture->store()->markPrepared($name, $token);
        $retry = $fixture->store()->markPrepared($name, $token);

        self::assertSame(WriterLeaseState::Prepared, $prepared->state());
        self::assertSame(WriterLeaseState::Prepared, $retry->state());
        self::assertSame(
            1,
            $fixture->store()->activeWriterCount(
                $name,
                SynchronizationEpoch::fromInt(1),
            ),
        );
    }

    public function test_mark_prepared_requires_valid_owner_sync_and_positive_original_epoch_count(): void
    {
        $name = $this->filterName();

        $ownership = $this->validFixture();
        $ownershipToken = $this->token('3');
        $ownership->store()->acquire($name, $ownershipToken);
        $ownership->putCoordination($name, false, null);

        try {
            $ownership->store()->markPrepared($name, $ownershipToken);
            self::fail('Expected invalid ownership to fence preparation.');
        } catch (CoordinationFenced) {
            self::assertSame(
                WriterLeaseState::Acquired,
                $ownership->store()->activeLeases($name)[0]->state(),
            );
        }

        $missingSync = $this->validFixture();
        $missingSyncToken = $this->token('4');
        $missingSync->store()->acquire($name, $missingSyncToken);
        $missingSync->putCoordination($name, true, null);

        try {
            $missingSync->store()->markPrepared($name, $missingSyncToken);
            self::fail('Expected missing synchronization state to fence preparation.');
        } catch (CoordinationFenced) {
            self::assertSame(
                1,
                $missingSync->store()->activeWriterCount(
                    $name,
                    SynchronizationEpoch::fromInt(1),
                ),
            );
        }

        $count = $this->validFixture();
        $countToken = $this->token('5');
        $count->store()->acquire($name, $countToken);
        $count->setActiveWriterCount(
            $name,
            SynchronizationEpoch::fromInt(1),
            0,
        );

        $this->expectException(CoordinationStateCorrupt::class);

        $count->store()->markPrepared($name, $countToken);
    }

    public function test_first_release_decrements_once_and_retry_is_idempotent(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('6');

        $fixture->store()->acquire($name, $token);
        $fixture->store()->markPrepared($name, $token);

        $released = $fixture->store()->release($name, $token);
        $retry = $fixture->store()->release($name, $token);

        self::assertSame(WriterLeaseState::Released, $released->state());
        self::assertSame(WriterLeaseState::Released, $retry->state());
        self::assertSame(
            0,
            $fixture->store()->activeWriterCount(
                $name,
                SynchronizationEpoch::fromInt(1),
            ),
        );
    }

    public function test_unknown_token_is_distinct(): void
    {
        $fixture = $this->validFixture();

        $this->expectException(UnknownWriterLease::class);

        $fixture->store()->markPrepared(
            $this->filterName(),
            $this->token('7'),
        );
    }

    public function test_released_token_is_terminal(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('8');

        $fixture->store()->acquire($name, $token);
        $fixture->store()->release($name, $token);

        $this->expectException(WriterLeaseReleased::class);

        $fixture->store()->acquire($name, $token);
    }

    public function test_count_underflow_is_corruption(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('9');

        $fixture->store()->acquire($name, $token);
        $fixture->setActiveWriterCount(
            $name,
            SynchronizationEpoch::fromInt(1),
            0,
        );

        $this->expectException(CoordinationStateCorrupt::class);

        $fixture->store()->release($name, $token);
    }

    public function test_active_lease_diagnostics_exclude_released_tombstones(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $released = $this->token('a');
        $prepared = $this->token('b');

        $fixture->store()->acquire($name, $released);
        $fixture->store()->acquire($name, $prepared);
        $fixture->store()->markPrepared($name, $prepared);
        $fixture->store()->release($name, $released);

        $active = $fixture->store()->activeLeases($name);

        self::assertCount(1, $active);
        self::assertTrue($active[0]->token()->equals($prepared));
        self::assertSame(WriterLeaseState::Prepared, $active[0]->state());
    }

    protected function validFixture(): WriterSynchronizationStoreContractFixture
    {
        $fixture = $this->newFixture();

        $fixture->putCoordination(
            $this->filterName(),
            true,
            $this->synchronizationState(
                revision: 1,
                epoch: 1,
                targets: [1, 2],
            ),
        );

        return $fixture;
    }

    protected function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    protected function token(string $suffix): WriterLeaseToken
    {
        return WriterLeaseToken::fromString(str_repeat($suffix, 32));
    }

    /**
     * @param  list<int>  $targets
     */
    protected function synchronizationState(
        int $revision,
        int $epoch,
        array $targets,
    ): SynchronizationState {
        return new SynchronizationState(
            revision: SynchronizationRevision::fromInt($revision),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt($epoch),
            currentTargets: SynchronizationTargetSet::fromVersions(array_map(
                static fn (int $version): FilterVersion => FilterVersion::fromInt($version),
                $targets,
            )),
            candidateVersion: null,
            drainingEpoch: null,
        );
    }

    /**
     * @return list<int>
     */
    protected function targetValues(SynchronizationTargetSet $targets): array
    {
        return array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $targets->versions(),
        );
    }
}
