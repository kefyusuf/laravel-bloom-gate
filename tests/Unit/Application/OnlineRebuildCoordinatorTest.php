<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

require_once __DIR__.'/../../Support/Application/Task9BuildFixtures.php';

use Kefyusuf\BloomGate\Application\AdoptionHandoff;
use Kefyusuf\BloomGate\Application\CoordinatedFilterAdopter;
use Kefyusuf\BloomGate\Application\OnlineRebuildCoordinator;
use Kefyusuf\BloomGate\Application\OptimalBloomSizingV1;
use Kefyusuf\BloomGate\Application\RebuildProgress;
use Kefyusuf\BloomGate\Application\SynchronizationFencedControlStore;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryWriterSynchronizationStore;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationEvidenceApplier;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9BuildEventLog;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9RecordingNormalizer;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StaticFilterRegistry;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StreamingAuthoritativeSet;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu09PromotionWinsLifecycleStore;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu09PublicationWinsLifecycleStore;
use PHPUnit\Framework\TestCase;

final class OnlineRebuildCoordinatorTest extends TestCase
{
    public function test_active_rebuild_advances_through_every_happy_path_phase_and_completes(): void
    {
        $environment = $this->environment(withActive: true);

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertCandidate($environment, LifecycleState::Configured, HealthState::Unavailable);
        $this->assertPhase($environment, SynchronizationPhase::Steady, 1, [1], null, null);

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertCandidate($environment, LifecycleState::Building, HealthState::Unavailable);

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertCandidate($environment, LifecycleState::Building, HealthState::Healthy);

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertCandidate($environment, LifecycleState::Shadow, HealthState::Healthy);

        $candidate = $this->candidateVersion($environment);
        $activeDescriptor = $environment['contracts']->read(
            $environment['name'],
            FilterVersion::fromInt(1),
        );
        $candidateDescriptor = $environment['contracts']->read(
            $environment['name'],
            $candidate,
        );

        self::assertNotNull($activeDescriptor);
        self::assertNotNull($candidateDescriptor);
        self::assertFalse(
            $activeDescriptor->layout()->equals($candidateDescriptor->layout()),
            'WU-08 semantic compatibility must not require layout equality.',
        );
        self::assertTrue(
            $activeDescriptor->semanticContract()->equals(
                $candidateDescriptor->semanticContract(),
            ),
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPreReconcile,
            2,
            [1, 2],
            2,
            1,
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Reconciling,
            2,
            [1, 2],
            2,
            null,
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertCandidate($environment, LifecycleState::Verified, HealthState::Healthy);
        $this->assertPhase(
            $environment,
            SynchronizationPhase::ReadyToPromote,
            2,
            [1, 2],
            2,
            null,
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $control = $this->control($environment);
        self::assertSame(2, $control->activeVersion()?->value());
        self::assertNull($control->candidateVersion());
        $this->assertPhase(
            $environment,
            SynchronizationPhase::ReadyToPromote,
            2,
            [1, 2],
            2,
            null,
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPostPromotion,
            3,
            [2],
            null,
            2,
        );

        self::assertSame(RebuildProgress::Completed, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Steady,
            3,
            [2],
            null,
            null,
        );
    }

    public function test_pre_reconcile_drain_uses_persisted_epoch_count_only(): void
    {
        $environment = $this->environment(withActive: true);

        $this->advanceUntilShadow($environment);

        $token = $this->token('a');
        $lease = $environment['writers']->acquire(
            $environment['name'],
            $token,
        );
        self::assertSame(1, $lease->epoch()->value());

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPreReconcile,
            2,
            [1, 2],
            2,
            1,
        );

        self::assertSame(RebuildProgress::Blocked, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPreReconcile,
            2,
            [1, 2],
            2,
            1,
        );

        $environment['writers']->release($environment['name'], $token);

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Reconciling,
            2,
            [1, 2],
            2,
            null,
        );
    }

    public function test_post_promotion_drain_blocks_completion_and_a_second_rebuild(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::DrainingPreReconcile,
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->advance($environment);

        $token = $this->token('b');
        $lease = $environment['writers']->acquire(
            $environment['name'],
            $token,
        );
        self::assertSame(2, $lease->epoch()->value());

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->advance($environment);
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPostPromotion,
            3,
            [2],
            null,
            2,
        );

        self::assertSame(RebuildProgress::Blocked, $this->advance($environment));
        self::assertNull($this->control($environment)->candidateVersion());

        $environment['writers']->release($environment['name'], $token);

        self::assertSame(RebuildProgress::Completed, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Steady,
            3,
            [2],
            null,
            null,
        );
    }

    public function test_ready_to_promote_recovery_rotates_targets_after_control_promotion_already_committed(): void
    {
        $environment = $this->environment(withActive: true);

        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::ReadyToPromote,
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $control = $this->control($environment);
        self::assertSame(2, $control->activeVersion()?->value());
        self::assertNull($control->candidateVersion());

        self::assertSame(
            RebuildProgress::Advanced,
            $this->coordinator($environment)->advance($environment['name']),
        );

        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPostPromotion,
            3,
            [2],
            null,
            2,
        );
    }

    public function test_publication_requires_each_active_and_candidate_semantic_fingerprint_to_match(): void
    {
        $semantic = $this->environmentSemantic();
        $mismatches = [
            'normalization' => new GenerationSemanticContract(
                normalizationFingerprint: NormalizationFingerprint::fromString(
                    'sha256:'.str_repeat('a', 64),
                ),
                authoritativeSetFingerprint: $semantic['authoritative'],
                consistencyFingerprint: $semantic['consistency'],
            ),
            'authoritative set' => new GenerationSemanticContract(
                normalizationFingerprint: $semantic['normalization'],
                authoritativeSetFingerprint: AuthoritativeSetFingerprint::fromString(
                    'sha256:'.str_repeat('b', 64),
                ),
                consistencyFingerprint: $semantic['consistency'],
            ),
            'consistency' => new GenerationSemanticContract(
                normalizationFingerprint: $semantic['normalization'],
                authoritativeSetFingerprint: $semantic['authoritative'],
                consistencyFingerprint: ConsistencyFingerprint::fromString(
                    'sha256:'.str_repeat('c', 64),
                ),
            ),
        ];

        foreach ($mismatches as $label => $mismatched) {
            $environment = $this->environment(
                withActive: true,
                activeSemantic: $mismatched,
            );

            $this->advanceUntilShadow($environment);

            self::assertSame(
                RebuildProgress::RecoveryRequired,
                $this->advance($environment),
                $label,
            );
            $this->assertPhase(
                $environment,
                SynchronizationPhase::Steady,
                1,
                [1],
                null,
                null,
            );
        }
    }

    public function test_reconciling_retry_resumes_when_control_verification_committed_before_sync_progress(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::Reconciling,
        );

        $snapshot = $environment['lifecycle']->read($environment['name']);
        $control = $snapshot->control();
        $sync = $snapshot->synchronization();

        self::assertNotNull($control);
        self::assertNotNull($sync);

        $candidate = $control->candidateVersion();
        self::assertNotNull($candidate);

        $descriptor = $environment['contracts']->read(
            $environment['name'],
            $candidate,
        );
        self::assertNotNull($descriptor);

        $definition = $environment['registry']
            ->get($environment['name'])
            ->definition();
        $normalized = [];

        foreach ($definition->authoritativeSet()->values() as $value) {
            $normalized[] = $definition->normalizer()->normalize($value);
        }

        $evidence = (new ActivationVerifier(
            $environment['probes'],
            $environment['driver'],
        ))->verify(
            $environment['name'],
            $candidate,
            $descriptor->layout(),
            $normalized,
        );
        $verified = (new ActivationVerificationEvidenceApplier)->apply(
            $control,
            $evidence,
        );

        $environment['lifecycle']->compareAndSwapControl(
            $environment['name'],
            $verified,
            $control->revision(),
            $sync->revision(),
        );

        $this->assertCandidate(
            $environment,
            LifecycleState::Verified,
            HealthState::Healthy,
        );
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Reconciling,
            2,
            [1, 2],
            2,
            null,
        );

        self::assertSame(
            RebuildProgress::Advanced,
            $this->advance($environment),
        );
        $this->assertCandidate(
            $environment,
            LifecycleState::Verified,
            HealthState::Healthy,
        );
        $this->assertPhase(
            $environment,
            SynchronizationPhase::ReadyToPromote,
            2,
            [1, 2],
            2,
            null,
        );
    }

    public function test_abort_requested_is_not_reversed_by_wu08_advance(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::DrainingPreReconcile,
        );

        $snapshot = $environment['lifecycle']->read($environment['name']);
        $control = $snapshot->control();
        $sync = $snapshot->synchronization();

        self::assertNotNull($control);
        self::assertNotNull($sync);

        $abortRequested = new SynchronizationState(
            revision: $sync->revision()->next(),
            phase: SynchronizationPhase::AbortRequested,
            currentEpoch: $sync->currentEpoch(),
            currentTargets: $sync->currentTargets(),
            candidateVersion: $sync->candidateVersion(),
            drainingEpoch: $sync->drainingEpoch(),
        );

        $environment['lifecycle']->compareAndSwapSynchronization(
            $environment['name'],
            $abortRequested,
            $sync->revision(),
            $control->revision(),
        );

        self::assertSame(
            RebuildProgress::RecoveryRequired,
            $this->advance($environment),
        );
        $this->assertPhase(
            $environment,
            SynchronizationPhase::AbortRequested,
            2,
            [1, 2],
            2,
            1,
        );
    }

    public function test_first_activation_uses_empty_initial_targets_and_same_online_workflow(): void
    {
        $environment = $this->environment(withActive: false);

        $this->assertPhase(
            $environment,
            SynchronizationPhase::Steady,
            1,
            [],
            null,
            null,
        );

        $this->advanceUntilShadow($environment);

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPreReconcile,
            2,
            [1],
            1,
            1,
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->advance($environment);
        $this->advance($environment);
        $this->advance($environment);
        self::assertSame(RebuildProgress::Completed, $this->advance($environment));

        $control = $this->control($environment);
        self::assertSame(1, $control->activeVersion()?->value());
        self::assertNull($control->candidateVersion());
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Steady,
            3,
            [1],
            null,
            null,
        );
    }

    public function test_unpublished_candidate_abort_retires_control_only_and_retry_is_complete(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilShadow($environment);

        $candidate = $this->candidateVersion($environment);
        $before = $environment['lifecycle']->read($environment['name'])->synchronization();

        self::assertNotNull($before);
        self::assertSame(SynchronizationPhase::Steady, $before->phase());

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));

        $control = $this->control($environment);
        self::assertNull($control->candidateVersion());
        $this->assertGeneration(
            $environment,
            $candidate,
            LifecycleState::Retired,
        );
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Steady,
            1,
            [1],
            null,
            null,
        );

        self::assertSame(RebuildProgress::Completed, $this->abort($environment));
    }

    public function test_abort_requested_waits_for_existing_pre_reconcile_drain_before_rotating_away_from_candidate(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilShadow($environment);

        $token = $this->token('d');
        $lease = $environment['writers']->acquire(
            $environment['name'],
            $token,
        );
        self::assertSame(1, $lease->epoch()->value());

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPreReconcile,
            2,
            [1, 2],
            2,
            1,
        );

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::AbortRequested,
            2,
            [1, 2],
            2,
            1,
        );

        self::assertSame(RebuildProgress::Blocked, $this->abort($environment));
        self::assertSame(RebuildProgress::RecoveryRequired, $this->advance($environment));
        self::assertSame(RebuildProgress::RecoveryRequired, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::AbortRequested,
            2,
            [1, 2],
            2,
            1,
        );

        $environment['writers']->release($environment['name'], $token);

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingAbort,
            3,
            [1],
            2,
            2,
        );
    }

    public function test_draining_abort_blocks_until_every_published_epoch_lease_drains_then_retires_and_finalizes(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::Reconciling,
        );

        $token = $this->token('e');
        $lease = $environment['writers']->acquire(
            $environment['name'],
            $token,
        );
        self::assertSame(2, $lease->epoch()->value());

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingAbort,
            3,
            [1],
            2,
            2,
        );

        self::assertSame(RebuildProgress::Blocked, $this->abort($environment));
        self::assertNotNull($this->control($environment)->candidateVersion());

        $environment['writers']->release($environment['name'], $token);

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        $control = $this->control($environment);
        self::assertNull($control->candidateVersion());
        $this->assertGeneration(
            $environment,
            FilterVersion::fromInt(2),
            LifecycleState::Retired,
        );
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingAbort,
            3,
            [1],
            2,
            2,
        );

        self::assertSame(RebuildProgress::Completed, $this->abort($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Steady,
            3,
            [1],
            null,
            null,
        );
    }

    public function test_draining_abort_recovers_after_candidate_retirement_committed_before_sync_finalization(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::Reconciling,
        );

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        $this->abort($environment);

        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingAbort,
            3,
            [1],
            2,
            2,
        );
        self::assertNull($this->control($environment)->candidateVersion());

        self::assertSame(
            RebuildProgress::Completed,
            $this->coordinator($environment)->abort($environment['name']),
        );
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Steady,
            3,
            [1],
            null,
            null,
        );
    }

    public function test_abort_after_control_promotion_is_too_late_and_advance_finishes_promotion_recovery(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::ReadyToPromote,
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));

        $control = $this->control($environment);
        self::assertSame(2, $control->activeVersion()?->value());
        self::assertNull($control->candidateVersion());
        $this->assertPhase(
            $environment,
            SynchronizationPhase::ReadyToPromote,
            2,
            [1, 2],
            2,
            null,
        );

        self::assertSame(
            RebuildProgress::RecoveryRequired,
            $this->abort($environment),
        );

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPostPromotion,
            3,
            [2],
            null,
            2,
        );
    }

    public function test_promotion_winning_same_observation_race_fences_stale_abort(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::ReadyToPromote,
        );

        $racing = new Wu09PromotionWinsLifecycleStore(
            $environment['lifecycle'],
        );

        try {
            $this->coordinator($environment, $racing)->abort(
                $environment['name'],
            );
            self::fail('Expected promotion to fence the stale abort synchronization CAS.');
        } catch (CoordinationWriteConflict) {
            $control = $this->control($environment);

            self::assertSame(2, $control->activeVersion()?->value());
            self::assertNull($control->candidateVersion());
            $this->assertPhase(
                $environment,
                SynchronizationPhase::ReadyToPromote,
                2,
                [1, 2],
                2,
                null,
            );
        }
    }

    public function test_abort_winning_same_observation_race_fences_stale_promotion(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilPhase(
            $environment,
            SynchronizationPhase::ReadyToPromote,
        );

        $snapshot = $environment['lifecycle']->read($environment['name']);
        $synchronization = $snapshot->synchronization();
        $control = $snapshot->control();

        self::assertNotNull($synchronization);
        self::assertNotNull($control);

        $candidate = $control->candidateVersion();
        self::assertNotNull($candidate);

        $stalePromoter = new CandidatePromoter(
            new SynchronizationFencedControlStore(
                lifecycle: $environment['lifecycle'],
                filterName: $environment['name'],
                expectedSynchronizationRevision: $synchronization->revision(),
            ),
        );

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingAbort,
            3,
            [1],
            2,
            2,
        );

        try {
            $stalePromoter->promote(
                $environment['name'],
                $candidate,
            );
            self::fail('Expected abort to fence the stale promotion control CAS.');
        } catch (CoordinationWriteConflict) {
            $control = $this->control($environment);

            self::assertSame(1, $control->activeVersion()?->value());
            self::assertSame(2, $control->candidateVersion()?->value());
        }
    }

    public function test_publication_winning_same_observation_race_fences_unpublished_control_only_abort(): void
    {
        $environment = $this->environment(withActive: true);
        $this->advanceUntilShadow($environment);

        $racing = new Wu09PublicationWinsLifecycleStore(
            $environment['lifecycle'],
        );

        try {
            $this->coordinator($environment, $racing)->abort(
                $environment['name'],
            );
            self::fail('Expected publication to fence the stale unpublished-candidate retirement.');
        } catch (CoordinationWriteConflict) {
            self::assertNotNull($this->control($environment)->candidateVersion());
            $this->assertPhase(
                $environment,
                SynchronizationPhase::DrainingPreReconcile,
                2,
                [1, 2],
                2,
                1,
            );
        }

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingAbort,
            3,
            [1],
            2,
            2,
        );
    }

    public function test_first_activation_abort_rotates_to_empty_targets_before_candidate_retirement(): void
    {
        $environment = $this->environment(withActive: false);
        $this->advanceUntilShadow($environment);

        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingPreReconcile,
            2,
            [1],
            1,
            1,
        );

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::DrainingAbort,
            3,
            [],
            1,
            2,
        );

        self::assertSame(RebuildProgress::Advanced, $this->abort($environment));
        self::assertNull($this->control($environment)->candidateVersion());

        self::assertSame(RebuildProgress::Completed, $this->abort($environment));
        $this->assertPhase(
            $environment,
            SynchronizationPhase::Steady,
            3,
            [],
            null,
            null,
        );
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function coordinator(
        array $environment,
        ?CoordinatedLifecycleStore $lifecycle = null,
    ): OnlineRebuildCoordinator {
        return new OnlineRebuildCoordinator(
            registry: $environment['registry'],
            sizing: new OptimalBloomSizingV1,
            lifecycle: $lifecycle ?? $environment['lifecycle'],
            writers: $environment['writers'],
            driver: $environment['driver'],
            generationContracts: $environment['contracts'],
            probes: $environment['probes'],
            fingerprints: $environment['fingerprints'],
            lifecyclePolicy: new LifecycleTransitionPolicy,
            verificationEvidence: new ActivationVerificationEvidenceApplier,
            activationVerifier: new ActivationVerifier(
                $environment['probes'],
                $environment['driver'],
            ),
            chunkSize: 2,
        );
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function advance(array $environment): RebuildProgress
    {
        return $this->coordinator($environment)->advance(
            $environment['name'],
        );
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function abort(array $environment): RebuildProgress
    {
        return $this->coordinator($environment)->abort(
            $environment['name'],
        );
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function advanceUntilShadow(array $environment): void
    {
        self::assertSame(RebuildProgress::Advanced, $this->advance($environment));
        $this->advance($environment);
        $this->advance($environment);
        $this->advance($environment);
        $this->assertCandidate(
            $environment,
            LifecycleState::Shadow,
            HealthState::Healthy,
        );
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function advanceUntilPhase(
        array $environment,
        SynchronizationPhase $phase,
    ): void {
        for ($step = 0; $step < 12; $step++) {
            $snapshot = $environment['lifecycle']->read($environment['name']);

            if ($snapshot->synchronization()?->phase() === $phase) {
                return;
            }

            $progress = $this->advance($environment);

            self::assertNotSame(RebuildProgress::RecoveryRequired, $progress);
            self::assertNotSame(RebuildProgress::Blocked, $progress);
        }

        self::fail('Expected WU-08 phase was not reached.');
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function assertGeneration(
        array $environment,
        FilterVersion $version,
        LifecycleState $lifecycle,
    ): void {
        foreach ($this->control($environment)->generations() as $generation) {
            if ($generation->version()->equals($version) === false) {
                continue;
            }

            self::assertSame($lifecycle, $generation->lifecycle());

            return;
        }

        self::fail('Expected tracked generation.');
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function assertCandidate(
        array $environment,
        LifecycleState $lifecycle,
        HealthState $health,
    ): void {
        $control = $this->control($environment);
        $candidate = $control->candidateVersion();

        self::assertNotNull($candidate);

        foreach ($control->generations() as $generation) {
            if ($generation->version()->equals($candidate) === false) {
                continue;
            }

            self::assertSame($lifecycle, $generation->lifecycle());
            self::assertSame($health, $generation->health());

            return;
        }

        self::fail('Expected current candidate generation.');
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     * @param  list<int>  $targets
     */
    private function assertPhase(
        array $environment,
        SynchronizationPhase $phase,
        int $epoch,
        array $targets,
        ?int $candidate,
        ?int $draining,
    ): void {
        $sync = $environment['lifecycle']
            ->read($environment['name'])
            ->synchronization();

        self::assertNotNull($sync);
        self::assertSame($phase, $sync->phase());
        self::assertSame($epoch, $sync->currentEpoch()->value());
        self::assertSame(
            $targets,
            array_map(
                static fn (FilterVersion $version): int => $version->value(),
                $sync->currentTargets()->versions(),
            ),
        );
        self::assertSame($candidate, $sync->candidateVersion()?->value());
        self::assertSame($draining, $sync->drainingEpoch()?->value());
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function control(array $environment): FilterControlState
    {
        $control = $environment['lifecycle']
            ->read($environment['name'])
            ->control();

        self::assertNotNull($control);

        return $control;
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }  $environment
     */
    private function candidateVersion(array $environment): FilterVersion
    {
        $candidate = $this->control($environment)->candidateVersion();

        self::assertNotNull($candidate);

        return $candidate;
    }

    /**
     * @return array{
     *     name: FilterName,
     *     registry: Task9StaticFilterRegistry,
     *     lifecycle: MemoryCoordinatedLifecycleStore,
     *     writers: MemoryWriterSynchronizationStore,
     *     driver: MemoryBloomDriver,
     *     contracts: MemoryGenerationContractStore,
     *     probes: BloomProbeGenerator,
     *     fingerprints: SemanticFingerprintCalculator
     * }
     */
    private function environment(
        bool $withActive,
        ?GenerationSemanticContract $activeSemantic = null,
    ): array {
        $name = FilterName::fromString('users.email');
        $events = new Task9BuildEventLog;
        $normalizer = new Task9RecordingNormalizer($events);
        $set = new Task9StreamingAuthoritativeSet(
            $events,
            ['one@example.test', 'two@example.test', 'three@example.test'],
        );
        $definition = new Task9FilterDefinition(
            $events,
            $normalizer,
            $set,
            ConsistencyContract::PreAddV1,
        );
        $registry = new Task9StaticFilterRegistry(
            new RegisteredFilter(
                name: $name,
                definition: $definition,
                queryOptimizationEnabled: true,
                capacity: 1_000,
                falsePositiveRate: 0.01,
            ),
        );

        $domain = new MemoryCoordinationDomain;
        $lifecycle = new MemoryCoordinatedLifecycleStore($domain);
        $legacyControl = new MemoryFilterControlStore($domain);
        $writers = new MemoryWriterSynchronizationStore($domain);
        $driver = new MemoryBloomDriver;
        $contracts = new MemoryGenerationContractStore($driver);
        $probes = new BloomProbeGenerator;
        $fingerprints = new SemanticFingerprintCalculator;
        $semantic = new GenerationSemanticContract(
            normalizationFingerprint: $fingerprints->normalization(
                $normalizer->identity(),
            ),
            authoritativeSetFingerprint: $fingerprints->authoritativeSet(
                $set->identity(),
            ),
            consistencyFingerprint: $fingerprints->consistency(
                ConsistencyContract::PreAddV1,
            ),
        );

        if ($withActive) {
            $version = FilterVersion::fromInt(1);
            $legacyControl->compareAndSwap(
                $name,
                new FilterControlState(
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
                ),
                null,
            );

            $activeLayout = BloomLayout::create(
                64,
                3,
                ProbeAlgorithm::Sha256DoubleHashV1,
            );
            $driver->provision($name, $version, $activeLayout);
            $contracts->bind(
                $name,
                $version,
                $activeLayout,
                $activeSemantic ?? $semantic,
            );

            (new CoordinatedFilterAdopter($lifecycle))->adopt(
                $name,
                AdoptionHandoff::Quiescent,
            );
        } else {
            (new CoordinatedFilterAdopter($lifecycle))->adopt($name);
        }

        return compact(
            'name',
            'registry',
            'lifecycle',
            'writers',
            'driver',
            'contracts',
            'probes',
            'fingerprints',
        );
    }

    /**
     * @return array{
     *     normalization: NormalizationFingerprint,
     *     authoritative: AuthoritativeSetFingerprint,
     *     consistency: ConsistencyFingerprint
     * }
     */
    private function environmentSemantic(): array
    {
        $environment = $this->environment(withActive: false);
        $registered = $environment['registry']->get($environment['name']);
        $definition = $registered->definition();
        $fingerprints = $environment['fingerprints'];

        return [
            'normalization' => $fingerprints->normalization(
                $definition->normalizer()->identity(),
            ),
            'authoritative' => $fingerprints->authoritativeSet(
                $definition->authoritativeSet()->identity(),
            ),
            'consistency' => $fingerprints->consistency(
                $definition->consistency(),
            ),
        ];
    }

    private function token(string $character): WriterLeaseToken
    {
        return WriterLeaseToken::fromString(str_repeat($character, 32));
    }
}
