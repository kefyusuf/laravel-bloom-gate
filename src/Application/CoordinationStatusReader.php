<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
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
use LogicException;
use Throwable;

final readonly class CoordinationStatusReader
{
    public function __construct(
        private CoordinatedLifecycleStore $lifecycle,
        private WriterSynchronizationStore $writers,
        private RuntimeCoordinationRequirement $requirement,
        private ?WriterLeaseInspector $leases = null,
    ) {}

    public function read(FilterName $name, bool $includeLeases = false): CoordinationStatus
    {
        $required = null;
        $sync = null;

        try {
            $required = $this->requirement->requiresCoordinatedV1($name);
            $snapshot = $this->lifecycle->read($name);
            $sync = $snapshot->synchronization();

            if (! $snapshot->ownershipClaimed()) {
                return new CoordinationStatus(
                    $required ? CoordinationStatusState::Invalid : CoordinationStatusState::Unadopted,
                    $required, issue: $required ? 'coordination_required' : null,
                );
            }

            if (! $required) {
                return new CoordinationStatus(CoordinationStatusState::Invalid, $required, $sync,
                    issue: 'runtime_coordination_not_required');
            }

            if ($sync === null) {
                return new CoordinationStatus(CoordinationStatusState::AdoptionPending, $required,
                    issue: 'adoption_pending');
            }

            if (! $this->validRelation($snapshot)) {
                return new CoordinationStatus(CoordinationStatusState::Invalid, $required, $sync,
                    issue: 'invalid_relation');
            }

            // Count and pair reads are separate observations. This count is diagnostic only.
            $count = $sync->drainingEpoch() === null ? null
                : $this->writers->activeWriterCount($name, $sync->drainingEpoch());

            if ($count !== null && $count < 0) {
                throw new CoordinationStateCorrupt('Diagnostic writer count cannot be negative.');
            }

            if ($sync->phase() === SynchronizationPhase::DrainingAbort
                && $snapshot->control()?->candidateVersion() === null && $count !== null && $count > 0) {
                return new CoordinationStatus(CoordinationStatusState::Invalid, $required, $sync, $count,
                    issue: 'invalid_relation');
            }

            $issue = match ($sync->phase()) {
                SynchronizationPhase::Steady => null,
                SynchronizationPhase::AbortRequested => 'abort_requested',
                SynchronizationPhase::DrainingAbort => 'abort_draining',
                SynchronizationPhase::ReadyToPromote => $snapshot->control()?->candidateVersion() === null
                    ? 'promotion_recovery' : 'rebuild_in_progress',
                default => $count !== null && $count > 0 ? 'draining_writers' : 'rebuild_in_progress',
            };

            if ($includeLeases && $this->leases === null) {
                throw new LogicException('Lease diagnostics are not configured.');
            }

            return new CoordinationStatus(CoordinationStatusState::Adopted, $required, $sync, $count, $issue,
                $includeLeases ? $this->leases->readActiveLeases($name) : []);
        } catch (InvalidConfiguration) {
            return new CoordinationStatus(CoordinationStatusState::Invalid, $required, $sync,
                issue: 'invalid_configuration');
        } catch (CoordinationStateCorrupt|FilterControlStateCorrupt) {
            return new CoordinationStatus(CoordinationStatusState::Invalid, $required, $sync,
                issue: 'storage_corrupt');
        } catch (Throwable) {
            return new CoordinationStatus(CoordinationStatusState::Unavailable, $required, $sync,
                issue: 'diagnostics_unavailable');
        }
    }

    private function validRelation(CoordinatedLifecycleSnapshot $snapshot): bool
    {
        $sync = $snapshot->synchronization();
        $control = $snapshot->control();

        if ($sync === null) {
            return false;
        }

        $drain = $sync->drainingEpoch();
        $needsDrain = in_array($sync->phase(), [SynchronizationPhase::DrainingPreReconcile,
            SynchronizationPhase::DrainingPostPromotion, SynchronizationPhase::AbortRequested,
            SynchronizationPhase::DrainingAbort], true);

        if ($needsDrain !== ($drain !== null)
            || ($drain !== null && $drain->value() !== $sync->currentEpoch()->value() - 1)) {
            return false;
        }

        if ($sync->phase() === SynchronizationPhase::Steady) {
            return $sync->candidateVersion() === null
                && $sync->currentTargets()->equals($this->steadyTargets($control));
        }

        if ($control === null) {
            return false;
        }

        if ($sync->phase() === SynchronizationPhase::DrainingPostPromotion) {
            $active = $control->activeVersion();

            return $active !== null && $control->candidateVersion() === null
                && $sync->candidateVersion() === null
                && $this->healthy($this->generation($control, $active), LifecycleState::Active)
                && $sync->currentTargets()->equals($this->steadyTargets($control));
        }

        $version = $sync->candidateVersion();
        if ($version === null) {
            return false;
        }

        $candidate = $this->generation($control, $version);
        $controlCandidate = $control->candidateVersion();

        if ($sync->phase() === SynchronizationPhase::ReadyToPromote && $controlCandidate === null) {
            return $control->activeVersion()?->equals($version) === true
                && $this->healthy($candidate, LifecycleState::Active)
                && $this->validPromotionTargets($control, $sync, $version);
        }

        if ($sync->phase() === SynchronizationPhase::DrainingAbort) {
            return $sync->currentTargets()->equals($this->steadyTargets($control))
                && ($controlCandidate === null
                    ? $candidate?->lifecycle() === LifecycleState::Retired
                    : $controlCandidate->equals($version)
                        && in_array($candidate?->lifecycle(), [LifecycleState::Shadow, LifecycleState::Verified], true));
        }

        if ($controlCandidate?->equals($version) !== true
            || ! $sync->currentTargets()->equals($this->publishedTargets($control, $version))) {
            return false;
        }

        return match ($sync->phase()) {
            SynchronizationPhase::DrainingPreReconcile => $this->healthy($candidate, LifecycleState::Shadow),
            SynchronizationPhase::Reconciling => $this->healthy($candidate, LifecycleState::Shadow)
                || $this->healthy($candidate, LifecycleState::Verified),
            SynchronizationPhase::ReadyToPromote => $this->healthy($candidate, LifecycleState::Verified),
            SynchronizationPhase::AbortRequested => in_array($candidate?->lifecycle(), [LifecycleState::Shadow, LifecycleState::Verified], true),
        };
    }

    private function steadyTargets(?FilterControlState $control): SynchronizationTargetSet
    {
        $active = $control?->activeVersion();

        return SynchronizationTargetSet::fromVersions($active === null ? [] : [$active]);
    }

    private function publishedTargets(FilterControlState $control, FilterVersion $candidate): SynchronizationTargetSet
    {
        $versions = [$candidate];
        if ($control->activeVersion() !== null) {
            $versions[] = $control->activeVersion();
        }
        usort($versions, static fn (FilterVersion $left, FilterVersion $right): int => $left->value() <=> $right->value());

        return SynchronizationTargetSet::fromVersions($versions);
    }

    private function validPromotionTargets(FilterControlState $control, SynchronizationState $sync, FilterVersion $version): bool
    {
        $targets = $sync->currentTargets();
        if (! $targets->contains($version) || count($targets->versions()) > 2) {
            return false;
        }
        foreach ($targets->versions() as $target) {
            if (! $target->equals($version) && $this->generation($control, $target)?->lifecycle() !== LifecycleState::Retired) {
                return false;
            }
        }

        return true;
    }

    private function generation(FilterControlState $control, FilterVersion $version): ?GenerationControlState
    {
        foreach ($control->generations() as $generation) {
            if ($generation->version()->equals($version)) {
                return $generation;
            }
        }

        return null;
    }

    private function healthy(?GenerationControlState $generation, LifecycleState $lifecycle): bool
    {
        return $generation?->lifecycle() === $lifecycle && $generation->health() === HealthState::Healthy;
    }
}
