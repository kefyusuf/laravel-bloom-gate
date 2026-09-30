<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use UnexpectedValueException;

final readonly class CoordinatedFilterAdopter
{
    public function __construct(
        private CoordinatedLifecycleStore $lifecycle,
    ) {}

    public function adopt(
        FilterName $name,
        AdoptionHandoff $handoff = AdoptionHandoff::NotAsserted,
    ): AdoptionResult {
        $snapshot = $this->lifecycle->read($name);

        $this->assertAdoptableControl(
            $snapshot->control(),
            $handoff,
        );

        if (! $snapshot->ownershipClaimed()) {
            $snapshot = $this->claimOwnership($name, $snapshot);
        }

        $this->assertAdoptableControl(
            $snapshot->control(),
            $handoff,
        );

        if ($snapshot->synchronization() !== null) {
            $this->assertAdoptedRelation($snapshot);

            return AdoptionResult::AlreadyAdopted;
        }

        $control = $snapshot->control();
        $next = new SynchronizationState(
            revision: SynchronizationRevision::fromInt(1),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt(1),
            currentTargets: $this->expectedTargets($control),
            candidateVersion: null,
            drainingEpoch: null,
        );

        try {
            $initialized = $this->lifecycle->compareAndSwapSynchronization(
                $name,
                $next,
                null,
                $control?->revision(),
            );
        } catch (CoordinationWriteConflict $failure) {
            $reread = $this->lifecycle->read($name);

            if (
                $reread->ownershipClaimed()
                && $reread->synchronization() !== null
            ) {
                $this->assertAdoptedRelation($reread);

                return AdoptionResult::AlreadyAdopted;
            }

            throw $failure;
        }

        $this->assertAdoptedRelation($initialized);

        return AdoptionResult::Adopted;
    }

    private function claimOwnership(
        FilterName $name,
        CoordinatedLifecycleSnapshot $snapshot,
    ): CoordinatedLifecycleSnapshot {
        try {
            return $this->lifecycle->claimOwnership(
                $name,
                $snapshot->control()?->revision(),
            );
        } catch (CoordinationFenced $failure) {
            $reread = $this->lifecycle->read($name);

            if ($reread->ownershipClaimed()) {
                return $reread;
            }

            throw $failure;
        }
    }

    private function assertAdoptableControl(
        ?FilterControlState $control,
        AdoptionHandoff $handoff,
    ): void {
        if ($control === null) {
            return;
        }

        if ($control->candidateVersion() !== null) {
            throw new InvalidArgumentException(
                'Brownfield coordinated adoption requires no current control candidate.',
            );
        }

        if ($handoff !== AdoptionHandoff::Quiescent) {
            throw new InvalidArgumentException(
                'Brownfield coordinated adoption requires an explicit quiescent handoff acknowledgement.',
            );
        }
    }

    private function assertAdoptedRelation(
        CoordinatedLifecycleSnapshot $snapshot,
    ): void {
        if (! $snapshot->ownershipClaimed()) {
            throw new UnexpectedValueException(
                'Coordinated adoption cannot complete without durable ownership.',
            );
        }

        $synchronization = $snapshot->synchronization();

        if (
            $synchronization === null
            || $synchronization->phase() !== SynchronizationPhase::Steady
            || $synchronization->candidateVersion() !== null
            || $synchronization->drainingEpoch() !== null
            || ! $synchronization->currentTargets()->equals(
                $this->expectedTargets($snapshot->control()),
            )
        ) {
            throw new UnexpectedValueException(
                'Existing coordinated state is not a valid STEADY adoption relation.',
            );
        }
    }

    private function expectedTargets(
        ?FilterControlState $control,
    ): SynchronizationTargetSet {
        $active = $control?->activeVersion();

        return SynchronizationTargetSet::fromVersions(
            $active === null ? [] : [$active],
        );
    }
}
