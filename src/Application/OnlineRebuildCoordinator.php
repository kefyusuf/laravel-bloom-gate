<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationEvidenceApplier;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;
use Kefyusuf\BloomGate\Lifecycle\CandidateAllocator;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;

final readonly class OnlineRebuildCoordinator
{
    public function __construct(
        private FilterRegistry $registry,
        private OptimalBloomSizingV1 $sizing,
        private CoordinatedLifecycleStore $lifecycle,
        private WriterSynchronizationStore $writers,
        private BulkBloomDriver $driver,
        private GenerationContractStore $generationContracts,
        private BloomProbeGenerator $probes,
        private SemanticFingerprintCalculator $fingerprints,
        private LifecycleTransitionPolicy $lifecyclePolicy,
        private ActivationVerificationEvidenceApplier $verificationEvidence,
        private ActivationVerifier $activationVerifier,
        private int $chunkSize,
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException(
                'Online rebuild chunk size must be at least 1.',
            );
        }
    }

    public function advance(FilterName $name): RebuildProgress
    {
        $registered = $this->registry->get($name);

        if (
            $registered->definition()->consistency()
            !== ConsistencyContract::PreAddV1
        ) {
            throw new InvalidArgumentException(
                'Online rebuild requires preadd-v1 consistency.',
            );
        }

        $snapshot = $this->lifecycle->read($name);
        $synchronization = $snapshot->synchronization();

        if (! $snapshot->ownershipClaimed() || $synchronization === null) {
            return RebuildProgress::RecoveryRequired;
        }

        return match ($synchronization->phase()) {
            SynchronizationPhase::Steady => $this->advanceSteady(
                $name,
                $registered,
                $snapshot,
            ),
            SynchronizationPhase::DrainingPreReconcile => $this->advancePreReconcileDrain(
                $name,
                $snapshot,
            ),
            SynchronizationPhase::Reconciling => $this->advanceReconciling(
                $name,
                $registered,
                $snapshot,
            ),
            SynchronizationPhase::ReadyToPromote => $this->advanceReadyToPromote(
                $name,
                $snapshot,
            ),
            SynchronizationPhase::DrainingPostPromotion => $this->advancePostPromotionDrain(
                $name,
                $snapshot,
            ),
            SynchronizationPhase::AbortRequested,
            SynchronizationPhase::DrainingAbort => RebuildProgress::RecoveryRequired,
        };
    }

    private function advanceSteady(
        FilterName $name,
        RegisteredFilter $registered,
        CoordinatedLifecycleSnapshot $snapshot,
    ): RebuildProgress {
        $synchronization = $snapshot->synchronization();

        if (
            $synchronization === null
            || $synchronization->candidateVersion() !== null
            || $synchronization->drainingEpoch() !== null
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $control = $snapshot->control();

        if (
            $synchronization->currentTargets()->equals(
                $this->steadyTargets($control),
            ) === false
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $store = $this->controlStore(
            $name,
            $synchronization,
        );

        if ($control === null || $control->candidateVersion() === null) {
            (new CandidateAllocator($store))->allocate($name);

            return RebuildProgress::Advanced;
        }

        $candidateVersion = $control->candidateVersion();
        $candidate = $this->generation(
            $control,
            $candidateVersion,
        );

        if ($candidate === null) {
            return RebuildProgress::RecoveryRequired;
        }

        if ($candidate->lifecycle() === LifecycleState::Configured) {
            if ($candidate->health() !== HealthState::Unavailable) {
                return RebuildProgress::RecoveryRequired;
            }

            (new GenerationLifecycleTransitioner(
                $store,
                $this->lifecyclePolicy,
            ))->transition(
                $name,
                $candidateVersion,
                LifecycleState::Building,
            );

            return RebuildProgress::Advanced;
        }

        if ($candidate->lifecycle() === LifecycleState::Building) {
            if ($candidate->health() === HealthState::Unavailable) {
                $this->buildCandidate(
                    $name,
                    $candidateVersion,
                    $registered,
                );

                (new GenerationHealthUpdater($store))->update(
                    $name,
                    $candidateVersion,
                    HealthState::Healthy,
                );

                return RebuildProgress::Advanced;
            }

            if ($candidate->health() !== HealthState::Healthy) {
                return RebuildProgress::RecoveryRequired;
            }

            (new GenerationLifecycleTransitioner(
                $store,
                $this->lifecyclePolicy,
            ))->transition(
                $name,
                $candidateVersion,
                LifecycleState::Shadow,
            );

            return RebuildProgress::Advanced;
        }

        if (
            $candidate->lifecycle() !== LifecycleState::Shadow
            || $candidate->health() !== HealthState::Healthy
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        if (
            $this->semanticCompatibility(
                $name,
                $registered,
                $control,
                $candidateVersion,
            ) === null
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $next = new SynchronizationState(
            revision: $synchronization->revision()->next(),
            phase: SynchronizationPhase::DrainingPreReconcile,
            currentEpoch: $synchronization->currentEpoch()->next(),
            currentTargets: $this->publishedTargets(
                $control,
                $candidateVersion,
            ),
            candidateVersion: $candidateVersion,
            drainingEpoch: $synchronization->currentEpoch(),
        );

        $this->lifecycle->compareAndSwapSynchronization(
            $name,
            $next,
            $synchronization->revision(),
            $control->revision(),
        );

        return RebuildProgress::Advanced;
    }

    private function advancePreReconcileDrain(
        FilterName $name,
        CoordinatedLifecycleSnapshot $snapshot,
    ): RebuildProgress {
        $synchronization = $snapshot->synchronization();
        $control = $snapshot->control();

        if (
            $synchronization === null
            || $control === null
            || $synchronization->candidateVersion() === null
            || $synchronization->drainingEpoch() === null
            || $this->validPublishedCandidateRelation(
                $control,
                $synchronization,
                LifecycleState::Shadow,
            ) === false
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        if (
            $this->writers->activeWriterCount(
                $name,
                $synchronization->drainingEpoch(),
            ) > 0
        ) {
            return RebuildProgress::Blocked;
        }

        $next = new SynchronizationState(
            revision: $synchronization->revision()->next(),
            phase: SynchronizationPhase::Reconciling,
            currentEpoch: $synchronization->currentEpoch(),
            currentTargets: $synchronization->currentTargets(),
            candidateVersion: $synchronization->candidateVersion(),
            drainingEpoch: null,
        );

        $this->lifecycle->compareAndSwapSynchronization(
            $name,
            $next,
            $synchronization->revision(),
            $control->revision(),
        );

        return RebuildProgress::Advanced;
    }

    private function advanceReconciling(
        FilterName $name,
        RegisteredFilter $registered,
        CoordinatedLifecycleSnapshot $snapshot,
    ): RebuildProgress {
        $synchronization = $snapshot->synchronization();
        $control = $snapshot->control();

        if (
            $synchronization === null
            || $control === null
            || $synchronization->candidateVersion() === null
            || $synchronization->drainingEpoch() !== null
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $candidateVersion = $synchronization->candidateVersion();
        $candidate = $this->generation(
            $control,
            $candidateVersion,
        );

        if (
            $candidate === null
            || $control->candidateVersion() === null
            || $control->candidateVersion()->equals($candidateVersion) === false
            || $candidate->health() !== HealthState::Healthy
            || (
                $candidate->lifecycle() !== LifecycleState::Shadow
                && $candidate->lifecycle() !== LifecycleState::Verified
            )
            || $synchronization->currentTargets()->equals(
                $this->publishedTargets(
                    $control,
                    $candidateVersion,
                ),
            ) === false
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $descriptor = $this->semanticCompatibility(
            $name,
            $registered,
            $control,
            $candidateVersion,
        );

        if ($descriptor === null) {
            return RebuildProgress::RecoveryRequired;
        }

        $this->addAuthoritativeValues(
            $name,
            $candidateVersion,
            $descriptor->layout(),
            $registered->definition(),
        );

        $result = $this->activationVerifier->verify(
            $name,
            $candidateVersion,
            $descriptor->layout(),
            $this->normalizedValues(
                $registered->definition()->authoritativeSet(),
                $registered->definition()->normalizer(),
            ),
        );

        $store = $this->controlStore(
            $name,
            $synchronization,
        );

        if (
            $result->status()
            === ActivationVerificationStatus::FalseNegativeDetected
        ) {
            (new GenerationHealthUpdater($store))->update(
                $name,
                $candidateVersion,
                HealthState::Stale,
            );

            return RebuildProgress::RecoveryRequired;
        }

        $controlForSync = $control;

        if ($candidate->lifecycle() === LifecycleState::Shadow) {
            $controlForSync = $this->verificationEvidence->apply(
                $control,
                $result,
            );
            $store->compareAndSwap(
                $name,
                $controlForSync,
                $control->revision(),
            );
        }

        $next = new SynchronizationState(
            revision: $synchronization->revision()->next(),
            phase: SynchronizationPhase::ReadyToPromote,
            currentEpoch: $synchronization->currentEpoch(),
            currentTargets: $synchronization->currentTargets(),
            candidateVersion: $candidateVersion,
            drainingEpoch: null,
        );

        $this->lifecycle->compareAndSwapSynchronization(
            $name,
            $next,
            $synchronization->revision(),
            $controlForSync->revision(),
        );

        return RebuildProgress::Advanced;
    }

    private function advanceReadyToPromote(
        FilterName $name,
        CoordinatedLifecycleSnapshot $snapshot,
    ): RebuildProgress {
        $synchronization = $snapshot->synchronization();
        $control = $snapshot->control();

        if (
            $synchronization === null
            || $control === null
            || $synchronization->candidateVersion() === null
            || $synchronization->drainingEpoch() !== null
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $candidateVersion = $synchronization->candidateVersion();

        if (
            $synchronization->currentTargets()->contains(
                $candidateVersion,
            ) === false
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $candidate = $this->generation(
            $control,
            $candidateVersion,
        );

        if (
            $control->candidateVersion() !== null
            && $control->candidateVersion()->equals($candidateVersion)
        ) {
            if (
                $candidate === null
                || $candidate->lifecycle() !== LifecycleState::Verified
                || $candidate->health() !== HealthState::Healthy
                || $synchronization->currentTargets()->equals(
                    $this->publishedTargets(
                        $control,
                        $candidateVersion,
                    ),
                ) === false
            ) {
                return RebuildProgress::RecoveryRequired;
            }

            (new CandidatePromoter(
                $this->controlStore(
                    $name,
                    $synchronization,
                ),
            ))->promote(
                $name,
                $candidateVersion,
            );

            return RebuildProgress::Advanced;
        }

        if (
            $control->candidateVersion() !== null
            || $control->activeVersion() === null
            || $control->activeVersion()->equals($candidateVersion) === false
            || $candidate === null
            || $candidate->lifecycle() !== LifecycleState::Active
            || $candidate->health() !== HealthState::Healthy
            || $this->validPromotionRecoveryTargets(
                $control,
                $synchronization->currentTargets(),
                $candidateVersion,
            ) === false
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $next = new SynchronizationState(
            revision: $synchronization->revision()->next(),
            phase: SynchronizationPhase::DrainingPostPromotion,
            currentEpoch: $synchronization->currentEpoch()->next(),
            currentTargets: SynchronizationTargetSet::fromVersions([
                $candidateVersion,
            ]),
            candidateVersion: null,
            drainingEpoch: $synchronization->currentEpoch(),
        );

        $this->lifecycle->compareAndSwapSynchronization(
            $name,
            $next,
            $synchronization->revision(),
            $control->revision(),
        );

        return RebuildProgress::Advanced;
    }

    private function advancePostPromotionDrain(
        FilterName $name,
        CoordinatedLifecycleSnapshot $snapshot,
    ): RebuildProgress {
        $synchronization = $snapshot->synchronization();
        $control = $snapshot->control();

        if (
            $synchronization === null
            || $control === null
            || $control->activeVersion() === null
            || $control->candidateVersion() !== null
            || $synchronization->candidateVersion() !== null
            || $synchronization->drainingEpoch() === null
            || $synchronization->currentTargets()->equals(
                SynchronizationTargetSet::fromVersions([
                    $control->activeVersion(),
                ]),
            ) === false
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        $active = $this->generation(
            $control,
            $control->activeVersion(),
        );

        if (
            $active === null
            || $active->lifecycle() !== LifecycleState::Active
            || $active->health() !== HealthState::Healthy
        ) {
            return RebuildProgress::RecoveryRequired;
        }

        if (
            $this->writers->activeWriterCount(
                $name,
                $synchronization->drainingEpoch(),
            ) > 0
        ) {
            return RebuildProgress::Blocked;
        }

        $next = new SynchronizationState(
            revision: $synchronization->revision()->next(),
            phase: SynchronizationPhase::Steady,
            currentEpoch: $synchronization->currentEpoch(),
            currentTargets: $synchronization->currentTargets(),
            candidateVersion: null,
            drainingEpoch: null,
        );

        $this->lifecycle->compareAndSwapSynchronization(
            $name,
            $next,
            $synchronization->revision(),
            $control->revision(),
        );

        return RebuildProgress::Completed;
    }

    private function buildCandidate(
        FilterName $name,
        FilterVersion $candidateVersion,
        RegisteredFilter $registered,
    ): void {
        $layout = $this->sizing->layout(
            $registered->capacity(),
            $registered->falsePositiveRate(),
        );
        $semantic = $this->runtimeSemantic($registered);

        $this->driver->provision(
            $name,
            $candidateVersion,
            $layout,
        );
        $this->generationContracts->bind(
            $name,
            $candidateVersion,
            $layout,
            $semantic,
        );
        $this->addAuthoritativeValues(
            $name,
            $candidateVersion,
            $layout,
            $registered->definition(),
        );
    }

    private function semanticCompatibility(
        FilterName $name,
        RegisteredFilter $registered,
        FilterControlState $control,
        FilterVersion $candidateVersion,
    ): ?ManagedGenerationDescriptor {
        $candidateDescriptor = $this->generationContracts->read(
            $name,
            $candidateVersion,
        );

        if (
            $candidateDescriptor === null
            || $candidateDescriptor->semanticContract()->equals(
                $this->runtimeSemantic($registered),
            ) === false
        ) {
            return null;
        }

        $activeVersion = $control->activeVersion();

        if ($activeVersion === null) {
            return $candidateDescriptor;
        }

        $activeDescriptor = $this->generationContracts->read(
            $name,
            $activeVersion,
        );

        if (
            $activeDescriptor === null
            || $activeDescriptor->semanticContract()->equals(
                $candidateDescriptor->semanticContract(),
            ) === false
        ) {
            return null;
        }

        return $candidateDescriptor;
    }

    private function runtimeSemantic(
        RegisteredFilter $registered,
    ): GenerationSemanticContract {
        $definition = $registered->definition();

        return new GenerationSemanticContract(
            normalizationFingerprint: $this->fingerprints->normalization(
                $definition->normalizer()->identity(),
            ),
            authoritativeSetFingerprint: $this->fingerprints->authoritativeSet(
                $definition->authoritativeSet()->identity(),
            ),
            consistencyFingerprint: $this->fingerprints->consistency(
                $definition->consistency(),
            ),
        );
    }

    private function addAuthoritativeValues(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
        FilterDefinition $definition,
    ): void {
        $normalizer = $definition->normalizer();
        $authoritativeSet = $definition->authoritativeSet();

        /** @var list<BitPositions> $batch */
        $batch = [];

        foreach ($authoritativeSet->values() as $value) {
            $batch[] = $this->probes->generate(
                $normalizer->normalize($value),
                $layout,
            );

            if (count($batch) !== $this->chunkSize) {
                continue;
            }

            $this->driver->addMany(
                $name,
                $version,
                $batch,
            );
            $batch = [];
        }

        if ($batch === []) {
            return;
        }

        $this->driver->addMany(
            $name,
            $version,
            $batch,
        );
    }

    /**
     * @return iterable<NormalizedValue>
     */
    private function normalizedValues(
        AuthoritativeSet $authoritativeSet,
        ValueNormalizer $normalizer,
    ): iterable {
        foreach ($authoritativeSet->values() as $value) {
            yield $normalizer->normalize($value);
        }
    }

    private function steadyTargets(
        ?FilterControlState $control,
    ): SynchronizationTargetSet {
        $active = $control?->activeVersion();

        return SynchronizationTargetSet::fromVersions(
            $active === null ? [] : [$active],
        );
    }

    private function publishedTargets(
        FilterControlState $control,
        FilterVersion $candidateVersion,
    ): SynchronizationTargetSet {
        $versions = [$candidateVersion];
        $active = $control->activeVersion();

        if ($active !== null) {
            $versions[] = $active;
        }

        usort(
            $versions,
            static fn (FilterVersion $left, FilterVersion $right): int => $left->value() <=> $right->value(),
        );

        return SynchronizationTargetSet::fromVersions(
            $versions,
        );
    }

    private function validPublishedCandidateRelation(
        FilterControlState $control,
        SynchronizationState $synchronization,
        LifecycleState $expectedLifecycle,
    ): bool {
        $candidateVersion = $synchronization->candidateVersion();

        if (
            $candidateVersion === null
            || $control->candidateVersion() === null
            || $control->candidateVersion()->equals($candidateVersion) === false
            || $synchronization->currentTargets()->equals(
                $this->publishedTargets(
                    $control,
                    $candidateVersion,
                ),
            ) === false
        ) {
            return false;
        }

        $candidate = $this->generation(
            $control,
            $candidateVersion,
        );

        return $candidate !== null
            && $candidate->lifecycle() === $expectedLifecycle
            && $candidate->health() === HealthState::Healthy;
    }

    private function validPromotionRecoveryTargets(
        FilterControlState $control,
        SynchronizationTargetSet $targets,
        FilterVersion $candidateVersion,
    ): bool {
        $versions = $targets->versions();

        if (
            $versions === []
            || count($versions) > 2
            || $targets->contains($candidateVersion) === false
        ) {
            return false;
        }

        foreach ($versions as $version) {
            if ($version->equals($candidateVersion)) {
                continue;
            }

            $generation = $this->generation(
                $control,
                $version,
            );

            if (
                $generation === null
                || $generation->lifecycle() !== LifecycleState::Retired
            ) {
                return false;
            }
        }

        return true;
    }

    private function generation(
        FilterControlState $control,
        FilterVersion $version,
    ): ?GenerationControlState {
        foreach ($control->generations() as $generation) {
            if ($generation->version()->equals($version)) {
                return $generation;
            }
        }

        return null;
    }

    private function controlStore(
        FilterName $name,
        SynchronizationState $synchronization,
    ): SynchronizationFencedControlStore {
        return new SynchronizationFencedControlStore(
            lifecycle: $this->lifecycle,
            filterName: $name,
            expectedSynchronizationRevision: $synchronization->revision(),
        );
    }
}
