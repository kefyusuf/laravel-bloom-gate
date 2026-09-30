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

    public function test_read_lease_is_exact_read_only_and_preserves_apr_state(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('f');

        self::assertNull($fixture->store()->readLease($name, $token));
        self::assertSame(
            0,
            $fixture->store()->activeWriterCount(
                $name,
                SynchronizationEpoch::fromInt(1),
            ),
        );

        $fixture->store()->acquire($name, $token);
        self::assertSame(
            WriterLeaseState::Acquired,
            $fixture->store()->readLease($name, $token)?->state(),
        );

        $fixture->store()->markPrepared($name, $token);
        self::assertSame(
            WriterLeaseState::Prepared,
            $fixture->store()->readLease($name, $token)?->state(),
        );

        $fixture->store()->release($name, $token);
        self::assertSame(
            WriterLeaseState::Released,
            $fixture->store()->readLease($name, $token)?->state(),
        );
    }

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

    public function test_acquire_requires_valid_owner_and_sync(): void
    {
        $name = $this->filterName();

        $unadopted = $this->newFixture();

        try {
            $unadopted->store()->acquire($name, $this->token('c'));
            self::fail('Expected unadopted writer acquire to fail closed.');
        } catch (CoordinationFenced) {
            self::addToAssertionCount(1);
        }

        $missingSync = $this->newFixture();
        $missingSync->putCoordination($name, true, null);

        $this->expectException(CoordinationFenced::class);

        $missingSync->store()->acquire($name, $this->token('d'));
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

    public function test_acquire_retry_on_prepared_lease_preserves_original_binding_and_count(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('a');

        $original = $fixture->store()->acquire($name, $token);
        $fixture->store()->markPrepared($name, $token);

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

        self::assertSame(WriterLeaseState::Prepared, $retry->state());
        self::assertSame($original->epoch()->value(), $retry->epoch()->value());
        self::assertSame(
            $this->targetValues($original->targets()),
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
            $ownership->putCoordination(
                $name,
                true,
                $this->synchronizationState(
                    revision: 1,
                    epoch: 1,
                    targets: [1, 2],
                ),
            );

            self::assertSame(
                WriterLeaseState::Acquired,
                $ownership->store()->acquire($name, $ownershipToken)->state(),
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
            $missingSync->putCoordination(
                $name,
                true,
                $this->synchronizationState(
                    revision: 1,
                    epoch: 1,
                    targets: [1, 2],
                ),
            );

            self::assertSame(
                WriterLeaseState::Acquired,
                $missingSync->store()->acquire($name, $missingSyncToken)->state(),
            );
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

    public function test_acquired_lease_can_release_once_without_preparation(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('b');

        $fixture->store()->acquire($name, $token);

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

    public function test_acquired_lease_releases_against_original_epoch_after_rotation(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('e');

        $fixture->store()->acquire($name, $token);

        $fixture->putCoordination(
            $name,
            true,
            $this->synchronizationState(
                revision: 2,
                epoch: 2,
                targets: [2],
            ),
        );

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
        self::assertSame(
            0,
            $fixture->store()->activeWriterCount(
                $name,
                SynchronizationEpoch::fromInt(2),
            ),
        );
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
        $name = $this->filterName();
        $token = $this->token('7');

        try {
            $fixture->store()->markPrepared($name, $token);
            self::fail('Expected unknown prepare token to remain distinct.');
        } catch (UnknownWriterLease) {
            self::assertSame(
                0,
                $fixture->store()->activeWriterCount(
                    $name,
                    SynchronizationEpoch::fromInt(1),
                ),
            );
        }

        $this->expectException(UnknownWriterLease::class);

        $fixture->store()->release($name, $token);
    }

    public function test_released_token_is_terminal(): void
    {
        $fixture = $this->validFixture();
        $name = $this->filterName();
        $token = $this->token('8');

        $fixture->store()->acquire($name, $token);
        $fixture->store()->release($name, $token);

        try {
            $fixture->store()->acquire($name, $token);
            self::fail('Expected released token to remain terminal on acquire.');
        } catch (WriterLeaseReleased) {
            self::assertSame(
                0,
                $fixture->store()->activeWriterCount(
                    $name,
                    SynchronizationEpoch::fromInt(1),
                ),
            );
        }

        $this->expectException(WriterLeaseReleased::class);

        $fixture->store()->markPrepared($name, $token);
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
