<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Contracts\WriterLeaseInspector;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;

final readonly class CoordinationStatusReader
{
    public function __construct(
        private CoordinatedLifecycleStore $lifecycle,
        private WriterSynchronizationStore $writers,
        private WriterLeaseInspector $leases,
        private RuntimeCoordinationRequirement $runtime,
    ) {}

    public function read(
        FilterName $name,
        bool $includeLeases = false,
    ): CoordinationStatus {
        $required = $this->runtime->requiresCoordinatedV1($name);

        try {
            $snapshot = $this->lifecycle->read($name);
        } catch (CoordinationStateCorrupt) {
            return $this->invalid(
                $required,
                CoordinationBlocker::InvalidState,
                $includeLeases,
            );
        } catch (CoordinationStoreOperationFailed) {
            return $this->invalid(
                $required,
                CoordinationBlocker::DiagnosticsUnavailable,
                $includeLeases,
            );
        }

        $synchronization = $snapshot->synchronization();

        if (! $snapshot->ownershipClaimed()) {
            if ($synchronization !== null) {
                return $this->invalid(
                    $required,
                    CoordinationBlocker::InvalidState,
                    $includeLeases,
                );
            }

            return new CoordinationStatus(
                ownership: CoordinationOwnership::Unadopted,
                runtimeRequiresCoordination: $required,
                synchronizationRevision: null,
                phase: null,
                currentEpoch: null,
                currentTargets: null,
                candidateVersion: null,
                drainingEpoch: null,
                drainingActiveWriterCount: null,
                blocker: $required
                    ? CoordinationBlocker::RuntimeConfigurationMismatch
                    : CoordinationBlocker::None,
                recovery: CoordinationRecovery::None,
                leasesIncluded: $includeLeases,
            );
        }

        if ($synchronization === null) {
            return new CoordinationStatus(
                ownership: CoordinationOwnership::AdoptionPending,
                runtimeRequiresCoordination: $required,
                synchronizationRevision: null,
                phase: null,
                currentEpoch: null,
                currentTargets: null,
                candidateVersion: null,
                drainingEpoch: null,
                drainingActiveWriterCount: null,
                blocker: CoordinationBlocker::AdoptionPending,
                recovery: CoordinationRecovery::None,
                leasesIncluded: $includeLeases,
            );
        }

        if (
            $this->validRelation(
                $snapshot->control(),
                $synchronization,
            ) === false
        ) {
            return $this->invalid(
                $required,
                CoordinationBlocker::InvalidState,
                $includeLeases,
            );
        }

        $drainingCount = null;

        if ($synchronization->drainingEpoch() !== null) {
            try {
                $drainingCount = $this->writers->activeWriterCount(
                    $name,
                    $synchronization->drainingEpoch(),
                );
            } catch (CoordinationStateCorrupt) {
                return $this->invalid(
                    $required,
                    CoordinationBlocker::InvalidState,
                    $includeLeases,
                );
            } catch (CoordinationStoreOperationFailed) {
                return $this->invalid(
                    $required,
                    CoordinationBlocker::DiagnosticsUnavailable,
                    $includeLeases,
                );
            }

            if (
                $this->candidateRetiredAbortShape(
                    $snapshot->control(),
                    $synchronization,
                )
                && $drainingCount > 0
            ) {
                return $this->invalid(
                    $required,
                    CoordinationBlocker::InvalidState,
                    $includeLeases,
                );
            }
        }

        $activeLeases = [];

        if ($includeLeases) {
            try {
                $activeLeases = $this->leases->activeLeases($name);
            } catch (CoordinationStateCorrupt) {
                return $this->invalid(
                    $required,
                    CoordinationBlocker::InvalidState,
                    true,
                );
            } catch (CoordinationStoreOperationFailed) {
                return $this->invalid(
                    $required,
                    CoordinationBlocker::DiagnosticsUnavailable,
                    true,
                );
            }
        }

        $blocker = CoordinationBlocker::None;

        if ($drainingCount !== null && $drainingCount > 0) {
            $blocker = CoordinationBlocker::DrainingWriters;
        } elseif (! $required) {
            $blocker = CoordinationBlocker::RuntimeConfigurationMismatch;
        }

        return new CoordinationStatus(
            ownership: CoordinationOwnership::Adopted,
            runtimeRequiresCoordination: $required,
            synchronizationRevision: $synchronization->revision(),
            phase: $synchronization->phase(),
            currentEpoch: $synchronization->currentEpoch(),
            currentTargets: $synchronization->currentTargets(),
            candidateVersion: $synchronization->candidateVersion(),
            drainingEpoch: $synchronization->drainingEpoch(),
            drainingActiveWriterCount: $drainingCount,
            blocker: $blocker,
            recovery: $this->recovery(
                $snapshot->control(),
                $synchronization,
            ),
            leasesIncluded: $includeLeases,
            activeLeases: $activeLeases,
        );
    }

    private function validRelation(
        ?FilterControlState $control,
        SynchronizationState $synchronization,
    ): bool {
        return match ($synchronization->phase()) {
            SynchronizationPhase::Steady => $synchronization->candidateVersion() === null
                && $synchronization->drainingEpoch() === null
                && $synchronization->currentTargets()->equals(
                    $this->steadyTargets($control),
                ),
            SynchronizationPhase::DrainingPreReconcile => $this->publishedCandidateRelation(
                $control,
                $synchronization,
                requireDrain: true,
                requireHealthy: true,
                allowedLifecycles: [LifecycleState::Shadow],
            ),
            SynchronizationPhase::Reconciling => $this->publishedCandidateRelation(
                $control,
                $synchronization,
                requireDrain: false,
                requireHealthy: false,
                allowedLifecycles: [
                    LifecycleState::Shadow,
                    LifecycleState::Verified,
                ],
            ),
            SynchronizationPhase::ReadyToPromote => $this->readyToPromoteRelation(
                $control,
                $synchronization,
            ),
            SynchronizationPhase::DrainingPostPromotion => $this->postPromotionDrainRelation(
                $control,
                $synchronization,
            ),
            SynchronizationPhase::AbortRequested => $this->publishedCandidateRelation(
                $control,
                $synchronization,
                requireDrain: true,
                requireHealthy: true,
                allowedLifecycles: [
                    LifecycleState::Shadow,
                    LifecycleState::Verified,
                ],
            ),
            SynchronizationPhase::DrainingAbort => $this->drainingAbortRelation(
                $control,
                $synchronization,
            ),
        };
    }

    /**
     * @param  list<LifecycleState>  $allowedLifecycles
     */
    private function publishedCandidateRelation(
        ?FilterControlState $control,
        SynchronizationState $synchronization,
        bool $requireDrain,
        bool $requireHealthy,
        array $allowedLifecycles,
    ): bool {
        if ($control === null || $synchronization->candidateVersion() === null) {
            return false;
        }

        if (
            $requireDrain !== ($synchronization->drainingEpoch() !== null)
        ) {
            return false;
        }

        $candidateVersion = $synchronization->candidateVersion();

        if (
            $control->candidateVersion() === null
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

        $candidate = $this->generation($control, $candidateVersion);

        return $candidate !== null
            && in_array(
                $candidate->lifecycle(),
                $allowedLifecycles,
                true,
            )
            && (
                $requireHealthy === false
                || $candidate->health() === HealthState::Healthy
            );
    }

    private function readyToPromoteRelation(
        ?FilterControlState $control,
        SynchronizationState $synchronization,
    ): bool {
        if (
            $control === null
            || $synchronization->candidateVersion() === null
            || $synchronization->drainingEpoch() !== null
        ) {
            return false;
        }

        $candidateVersion = $synchronization->candidateVersion();
        $candidate = $this->generation($control, $candidateVersion);

        if (
            $control->candidateVersion() !== null
            && $control->candidateVersion()->equals($candidateVersion)
        ) {
            return $candidate !== null
                && $candidate->lifecycle() === LifecycleState::Verified
                && $candidate->health() === HealthState::Healthy
                && $synchronization->currentTargets()->equals(
                    $this->publishedTargets(
                        $control,
                        $candidateVersion,
                    ),
                );
        }

        return $control->candidateVersion() === null
            && $control->activeVersion() !== null
            && $control->activeVersion()->equals($candidateVersion)
            && $candidate !== null
            && $candidate->lifecycle() === LifecycleState::Active
            && $candidate->health() === HealthState::Healthy
            && $this->validPromotionRecoveryTargets(
                $control,
                $synchronization->currentTargets(),
                $candidateVersion,
            );
    }

    private function postPromotionDrainRelation(
        ?FilterControlState $control,
        SynchronizationState $synchronization,
    ): bool {
        if (
            $control === null
            || $control->activeVersion() === null
            || $control->candidateVersion() !== null
            || $synchronization->candidateVersion() !== null
            || $synchronization->drainingEpoch() === null
        ) {
            return false;
        }

        $active = $this->generation(
            $control,
            $control->activeVersion(),
        );

        return $active !== null
            && $active->lifecycle() === LifecycleState::Active
            && $active->health() === HealthState::Healthy
            && $synchronization->currentTargets()->equals(
                SynchronizationTargetSet::fromVersions([
                    $control->activeVersion(),
                ]),
            );
    }

    private function drainingAbortRelation(
        ?FilterControlState $control,
        SynchronizationState $synchronization,
    ): bool {
        if (
            $control === null
            || $synchronization->candidateVersion() === null
            || $synchronization->drainingEpoch() === null
            || $synchronization->currentTargets()->equals(
                $this->steadyTargets($control),
            ) === false
        ) {
            return false;
        }

        $candidateVersion = $synchronization->candidateVersion();
        $candidate = $this->generation(
            $control,
            $candidateVersion,
        );

        if ($control->candidateVersion() !== null) {
            return $control->candidateVersion()->equals($candidateVersion)
                && $candidate !== null
                && (
                    $candidate->lifecycle() === LifecycleState::Shadow
                    || $candidate->lifecycle() === LifecycleState::Verified
                );
        }

        return $candidate !== null
            && $candidate->lifecycle() === LifecycleState::Retired;
    }

    private function recovery(
        ?FilterControlState $control,
        SynchronizationState $synchronization,
    ): CoordinationRecovery {
        return match ($synchronization->phase()) {
            SynchronizationPhase::ReadyToPromote => $control?->candidateVersion() === null
                ? CoordinationRecovery::PromotionSynchronizationPending
                : CoordinationRecovery::None,
            SynchronizationPhase::AbortRequested => CoordinationRecovery::AbortRequested,
            SynchronizationPhase::DrainingAbort => $this->candidateRetiredAbortShape(
                $control,
                $synchronization,
            )
                ? CoordinationRecovery::AbortFinalizationPending
                : CoordinationRecovery::AbortDraining,
            SynchronizationPhase::Reconciling => $this->candidateHealthy(
                $control,
                $synchronization->candidateVersion(),
            )
                ? CoordinationRecovery::None
                : CoordinationRecovery::RebuildRecoveryRequired,
            default => CoordinationRecovery::None,
        };
    }

    private function candidateRetiredAbortShape(
        ?FilterControlState $control,
        SynchronizationState $synchronization,
    ): bool {
        if (
            $control === null
            || $control->candidateVersion() !== null
            || $synchronization->phase() !== SynchronizationPhase::DrainingAbort
            || $synchronization->candidateVersion() === null
        ) {
            return false;
        }

        return $this->generation(
            $control,
            $synchronization->candidateVersion(),
        )?->lifecycle() === LifecycleState::Retired;
    }

    private function candidateHealthy(
        ?FilterControlState $control,
        ?FilterVersion $candidateVersion,
    ): bool {
        if ($control === null || $candidateVersion === null) {
            return false;
        }

        return $this->generation(
            $control,
            $candidateVersion,
        )?->health() === HealthState::Healthy;
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

        return SynchronizationTargetSet::fromVersions($versions);
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

            if (
                $this->generation(
                    $control,
                    $version,
                )?->lifecycle() !== LifecycleState::Retired
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

    private function invalid(
        bool $required,
        CoordinationBlocker $blocker,
        bool $leasesIncluded,
    ): CoordinationStatus {
        return new CoordinationStatus(
            ownership: CoordinationOwnership::Invalid,
            runtimeRequiresCoordination: $required,
            synchronizationRevision: null,
            phase: null,
            currentEpoch: null,
            currentTargets: null,
            candidateVersion: null,
            drainingEpoch: null,
            drainingActiveWriterCount: null,
            blocker: $blocker,
            recovery: CoordinationRecovery::None,
            leasesIncluded: $leasesIncluded,
        );
    }
}
