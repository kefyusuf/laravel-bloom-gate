<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Application\AdoptionHandoff;
use Kefyusuf\BloomGate\Application\AdoptionResult;
use Kefyusuf\BloomGate\Application\CoordinatedFilterAdopter;
use Kefyusuf\BloomGate\Application\CoordinatedLeaseRecovery;
use Kefyusuf\BloomGate\Application\LeaseResolutionEvidenceInsufficient;
use Kefyusuf\BloomGate\Application\LeaseResolutionResult;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Core\AuthoritativeOutcome;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryWriterSynchronizationStore;
use PHPUnit\Framework\TestCase;

final class CoordinatedAdoptionAndLeaseRecoveryTest extends TestCase
{
    public function test_fresh_filter_adopts_without_brownfield_handoff_and_retry_is_idempotent(): void
    {
        $environment = $this->environment();

        $first = $environment['adopter']->adopt($environment['name']);
        $second = $environment['adopter']->adopt($environment['name']);
        $snapshot = $environment['lifecycle']->read($environment['name']);
        $synchronization = $snapshot->synchronization();

        self::assertSame(AdoptionResult::Adopted, $first);
        self::assertSame(AdoptionResult::AlreadyAdopted, $second);
        self::assertTrue($snapshot->ownershipClaimed());
        self::assertNotNull($synchronization);
        self::assertSame(1, $synchronization->revision()->value());
        self::assertSame(SynchronizationPhase::Steady, $synchronization->phase());
        self::assertSame(1, $synchronization->currentEpoch()->value());
        self::assertTrue($synchronization->currentTargets()->isEmpty());
        self::assertNull($synchronization->candidateVersion());
        self::assertNull($synchronization->drainingEpoch());
    }

    public function test_brownfield_adoption_requires_explicit_quiescent_handoff_and_targets_current_active(): void
    {
        $environment = $this->environment();
        $environment['control']->compareAndSwap(
            $environment['name'],
            $this->controlState($environment['name']),
            null,
        );

        try {
            $environment['adopter']->adopt($environment['name']);
            self::fail('Expected brownfield adoption without quiescent handoff to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertFalse(
                $environment['lifecycle']->read($environment['name'])->ownershipClaimed(),
            );
        }

        $result = $environment['adopter']->adopt(
            $environment['name'],
            AdoptionHandoff::Quiescent,
        );
        $snapshot = $environment['lifecycle']->read($environment['name']);
        $synchronization = $snapshot->synchronization();

        self::assertSame(AdoptionResult::Adopted, $result);
        self::assertNotNull($synchronization);
        self::assertSame(
            [1],
            array_map(
                static fn (FilterVersion $version): int => $version->value(),
                $synchronization->currentTargets()->versions(),
            ),
        );
    }

    public function test_brownfield_candidate_blocks_adoption_before_ownership_claim(): void
    {
        $environment = $this->environment();
        $environment['control']->compareAndSwap(
            $environment['name'],
            $this->controlState($environment['name'], candidate: true),
            null,
        );

        $this->expectException(InvalidArgumentException::class);

        try {
            $environment['adopter']->adopt(
                $environment['name'],
                AdoptionHandoff::Quiescent,
            );
        } finally {
            self::assertFalse(
                $environment['lifecycle']->read($environment['name'])->ownershipClaimed(),
            );
        }
    }

    public function test_owner_only_adoption_pending_fences_legacy_mutation_and_writer_acquire_then_resumes(): void
    {
        $environment = $this->environment();
        $current = $this->controlState($environment['name']);
        $environment['control']->compareAndSwap($environment['name'], $current, null);
        $environment['lifecycle']->claimOwnership(
            $environment['name'],
            $current->revision(),
        );

        $pending = $environment['lifecycle']->read($environment['name']);
        self::assertTrue($pending->ownershipClaimed());
        self::assertNull($pending->synchronization());

        try {
            $environment['control']->compareAndSwap(
                $environment['name'],
                $this->controlState($environment['name'], revision: 2),
                $current->revision(),
            );
            self::fail('Expected ADOPTION_PENDING to fence ordinary M5 mutation.');
        } catch (CoordinationFenced) {
            self::addToAssertionCount(1);
        }

        try {
            $environment['writer']->acquire(
                $environment['name'],
                $this->token('a'),
            );
            self::fail('Expected ADOPTION_PENDING to fence coordinated writer acquisition.');
        } catch (CoordinationFenced) {
            self::addToAssertionCount(1);
        }

        $result = $environment['adopter']->adopt(
            $environment['name'],
            AdoptionHandoff::Quiescent,
        );
        $synchronization = $environment['lifecycle']
            ->read($environment['name'])
            ->synchronization();

        self::assertSame(AdoptionResult::Adopted, $result);
        self::assertNotNull($synchronization);
        self::assertSame(SynchronizationPhase::Steady, $synchronization->phase());
        self::assertSame([1], array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $synchronization->currentTargets()->versions(),
        ));
    }

    public function test_exact_token_lookup_never_acquires_an_unknown_lease_and_retains_apr_tombstone_state(): void
    {
        $environment = $this->environment();
        $environment['adopter']->adopt($environment['name']);
        $token = $this->token('b');

        self::assertNull(
            $environment['writer']->readLease($environment['name'], $token),
        );
        self::assertSame(
            0,
            $environment['writer']->activeWriterCount(
                $environment['name'],
                SynchronizationEpoch::fromInt(1),
            ),
        );

        $environment['writer']->acquire($environment['name'], $token);
        self::assertSame(
            WriterLeaseState::Acquired,
            $environment['writer']->readLease($environment['name'], $token)?->state(),
        );

        $environment['writer']->markPrepared($environment['name'], $token);
        self::assertSame(
            WriterLeaseState::Prepared,
            $environment['writer']->readLease($environment['name'], $token)?->state(),
        );

        $environment['writer']->release($environment['name'], $token);
        self::assertSame(
            WriterLeaseState::Released,
            $environment['writer']->readLease($environment['name'], $token)?->state(),
        );
    }

    public function test_known_committed_prepared_lease_releases(): void
    {
        $environment = $this->adoptedWriterEnvironment();
        $token = $this->token('c');
        $environment['writer']->acquire($environment['name'], $token);
        $environment['writer']->markPrepared($environment['name'], $token);

        $result = $environment['recovery']->resolve(
            $environment['name'],
            $token,
            AuthoritativeOutcome::Committed,
        );

        self::assertSame(LeaseResolutionResult::Released, $result);
        self::assertSame(
            WriterLeaseState::Released,
            $environment['writer']->readLease($environment['name'], $token)?->state(),
        );
        self::assertSame(0, $this->activeCount($environment));
    }

    public function test_known_committed_acquired_lease_is_blocked_without_preparation_evidence(): void
    {
        $environment = $this->adoptedWriterEnvironment();
        $token = $this->token('d');
        $environment['writer']->acquire($environment['name'], $token);

        try {
            $environment['recovery']->resolve(
                $environment['name'],
                $token,
                AuthoritativeOutcome::Committed,
            );
            self::fail('Expected committed A lease recovery to be rejected.');
        } catch (LeaseResolutionEvidenceInsufficient) {
            self::assertSame(
                WriterLeaseState::Acquired,
                $environment['writer']->readLease($environment['name'], $token)?->state(),
            );
            self::assertSame(1, $this->activeCount($environment));
        }
    }

    public function test_known_rollback_releases_acquired_or_prepared_lease(): void
    {
        foreach ([
            'acquired' => false,
            'prepared' => true,
        ] as $suffix => $prepared) {
            $environment = $this->adoptedWriterEnvironment();
            $token = $this->token($prepared ? 'e' : 'f');
            $environment['writer']->acquire($environment['name'], $token);

            if ($prepared) {
                $environment['writer']->markPrepared($environment['name'], $token);
            }

            self::assertSame(
                LeaseResolutionResult::Released,
                $environment['recovery']->resolve(
                    $environment['name'],
                    $token,
                    AuthoritativeOutcome::RolledBack,
                ),
                $suffix,
            );
            self::assertSame(0, $this->activeCount($environment), $suffix);
        }
    }

    public function test_unknown_authoritative_outcome_never_releases(): void
    {
        $environment = $this->adoptedWriterEnvironment();
        $token = $this->token('1');
        $environment['writer']->acquire($environment['name'], $token);
        $environment['writer']->markPrepared($environment['name'], $token);

        try {
            $environment['recovery']->resolve(
                $environment['name'],
                $token,
                AuthoritativeOutcome::Unknown,
            );
            self::fail('Expected unknown authoritative outcome to remain unresolved.');
        } catch (InvalidArgumentException) {
            self::assertSame(
                WriterLeaseState::Prepared,
                $environment['writer']->readLease($environment['name'], $token)?->state(),
            );
            self::assertSame(1, $this->activeCount($environment));
        }
    }

    public function test_released_token_is_terminal_and_resolution_is_idempotent(): void
    {
        $environment = $this->adoptedWriterEnvironment();
        $token = $this->token('2');
        $environment['writer']->acquire($environment['name'], $token);
        $environment['writer']->markPrepared($environment['name'], $token);
        $environment['writer']->release($environment['name'], $token);

        self::assertSame(
            LeaseResolutionResult::AlreadyReleased,
            $environment['recovery']->resolve(
                $environment['name'],
                $token,
                AuthoritativeOutcome::Committed,
            ),
        );
        self::assertSame(0, $this->activeCount($environment));
    }

    public function test_unknown_token_is_rejected_without_creating_a_lease(): void
    {
        $environment = $this->adoptedWriterEnvironment();
        $token = $this->token('3');

        try {
            $environment['recovery']->resolve(
                $environment['name'],
                $token,
                AuthoritativeOutcome::RolledBack,
            );
            self::fail('Expected unknown lease resolution to be rejected.');
        } catch (UnknownWriterLease) {
            self::assertNull(
                $environment['writer']->readLease($environment['name'], $token),
            );
            self::assertSame(0, $this->activeCount($environment));
        }
    }

    /**
     * @return array{
     *     name: FilterName,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     control: MemoryFilterControlStore,
     *     writer: MemoryWriterSynchronizationStore,
     *     adopter: CoordinatedFilterAdopter
     * }
     */
    private function environment(): array
    {
        $name = FilterName::fromString('users.email');
        $domain = new MemoryCoordinationDomain;
        $lifecycle = new MemoryCoordinatedLifecycleStore($domain);
        $control = new MemoryFilterControlStore($domain);
        $writer = new MemoryWriterSynchronizationStore($domain);
        $adopter = new CoordinatedFilterAdopter($lifecycle);

        return compact('name', 'lifecycle', 'control', 'writer', 'adopter');
    }

    /**
     * @return array{
     *     name: FilterName,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     control: MemoryFilterControlStore,
     *     writer: MemoryWriterSynchronizationStore,
     *     adopter: CoordinatedFilterAdopter,
     *     recovery: CoordinatedLeaseRecovery
     * }
     */
    private function adoptedWriterEnvironment(): array
    {
        $environment = $this->environment();
        $environment['adopter']->adopt($environment['name']);
        $environment['recovery'] = new CoordinatedLeaseRecovery(
            $environment['writer'],
        );

        return $environment;
    }

    private function controlState(
        FilterName $name,
        int $revision = 1,
        bool $candidate = false,
    ): FilterControlState {
        $active = FilterVersion::fromInt(1);
        $candidateVersion = $candidate
            ? FilterVersion::fromInt(2)
            : null;

        $generations = [
            new GenerationControlState(
                version: $active,
                lifecycle: LifecycleState::Active,
                health: HealthState::Healthy,
            ),
        ];

        if ($candidateVersion !== null) {
            $generations[] = new GenerationControlState(
                version: $candidateVersion,
                lifecycle: LifecycleState::Shadow,
                health: HealthState::Healthy,
            );
        }

        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt($revision),
            lastAllocatedVersion: $candidateVersion ?? $active,
            activeVersion: $active,
            candidateVersion: $candidateVersion,
            generations: $generations,
        );
    }

    private function token(string $character): WriterLeaseToken
    {
        return WriterLeaseToken::fromString(str_repeat($character, 32));
    }

    /**
     * @param array{
     *     name: FilterName,
     *     writer: MemoryWriterSynchronizationStore
     * } $environment
     */
    private function activeCount(array $environment): int
    {
        return $environment['writer']->activeWriterCount(
            $environment['name'],
            SynchronizationEpoch::fromInt(1),
        );
    }
}
