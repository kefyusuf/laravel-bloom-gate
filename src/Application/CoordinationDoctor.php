<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\ProductionSafetyCheckStatus;

final readonly class CoordinationDoctor
{
    public function __construct(
        private CoordinationStatusReader $status,
    ) {}

    public function inspect(FilterName $name): ProductionSafetyReport
    {
        $status = $this->status->read(
            $name,
            includeLeases: true,
        );

        return new ProductionSafetyReport([
            $this->check(
                'coordination.ownership',
                $this->ownershipStatus($status),
                $this->ownershipMessage($status),
            ),
            $this->check(
                'coordination.runtime',
                $this->runtimeStatus($status),
                $this->runtimeMessage($status),
            ),
            $this->check(
                'coordination.state',
                $this->stateStatus($status),
                $this->stateMessage($status),
            ),
            $this->check(
                'coordination.drain',
                $this->drainStatus($status),
                $this->drainMessage($status),
            ),
            $this->check(
                'coordination.recovery',
                $status->recovery() === CoordinationRecovery::None
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Warn,
                $status->recovery() === CoordinationRecovery::None
                    ? 'No coordinated workflow recovery is pending.'
                    : 'A coordinated workflow recovery state is pending.',
            ),
        ]);
    }

    private function ownershipStatus(
        CoordinationStatus $status,
    ): ProductionSafetyCheckStatus {
        return match ($status->ownership()) {
            CoordinationOwnership::Unadopted => ProductionSafetyCheckStatus::NotEnabled,
            CoordinationOwnership::Adopted => ProductionSafetyCheckStatus::Pass,
            CoordinationOwnership::AdoptionPending,
            CoordinationOwnership::Invalid => ProductionSafetyCheckStatus::Fail,
        };
    }

    private function ownershipMessage(
        CoordinationStatus $status,
    ): string {
        return match ($status->ownership()) {
            CoordinationOwnership::Unadopted => 'Coordinated ownership is not present.',
            CoordinationOwnership::AdoptionPending => 'Coordinated ownership exists but synchronization initialization is incomplete.',
            CoordinationOwnership::Adopted => 'Coordinated ownership and synchronization state are present.',
            CoordinationOwnership::Invalid => 'Coordinated ownership or synchronization state is invalid or unavailable.',
        };
    }

    private function runtimeStatus(
        CoordinationStatus $status,
    ): ProductionSafetyCheckStatus {
        if (
            $status->runtimeRequiresCoordination()
            && $status->ownership() === CoordinationOwnership::Unadopted
        ) {
            return ProductionSafetyCheckStatus::Fail;
        }

        if (
            ! $status->runtimeRequiresCoordination()
            && $status->ownership() !== CoordinationOwnership::Unadopted
        ) {
            return ProductionSafetyCheckStatus::Fail;
        }

        return ProductionSafetyCheckStatus::Pass;
    }

    private function runtimeMessage(
        CoordinationStatus $status,
    ): string {
        if (
            $status->runtimeRequiresCoordination()
            && $status->ownership() === CoordinationOwnership::Unadopted
        ) {
            return 'Runtime requires coordinated-v1 but durable coordinated ownership is absent.';
        }

        if (
            ! $status->runtimeRequiresCoordination()
            && $status->ownership() !== CoordinationOwnership::Unadopted
        ) {
            return 'Durable coordinated ownership exists while the runtime requests legacy mode.';
        }

        return 'Runtime coordination requirement agrees with durable ownership.';
    }

    private function stateStatus(
        CoordinationStatus $status,
    ): ProductionSafetyCheckStatus {
        return match ($status->ownership()) {
            CoordinationOwnership::Unadopted => ProductionSafetyCheckStatus::NotEnabled,
            CoordinationOwnership::Adopted => ProductionSafetyCheckStatus::Pass,
            CoordinationOwnership::AdoptionPending,
            CoordinationOwnership::Invalid => ProductionSafetyCheckStatus::Fail,
        };
    }

    private function stateMessage(
        CoordinationStatus $status,
    ): string {
        return match ($status->ownership()) {
            CoordinationOwnership::Unadopted => 'No coordinated state is expected for this unadopted filter.',
            CoordinationOwnership::AdoptionPending => 'Coordinated adoption is incomplete and requires recovery.',
            CoordinationOwnership::Adopted => 'Control and synchronization state form a valid coordinated relation.',
            CoordinationOwnership::Invalid => 'Coordination diagnostics detected an invalid, corrupt, or unavailable state.',
        };
    }

    private function drainStatus(
        CoordinationStatus $status,
    ): ProductionSafetyCheckStatus {
        if ($status->drainingEpoch() === null) {
            return ProductionSafetyCheckStatus::NotEnabled;
        }

        if ($status->blocker() === CoordinationBlocker::DrainingWriters) {
            return ProductionSafetyCheckStatus::Warn;
        }

        return ProductionSafetyCheckStatus::Pass;
    }

    private function drainMessage(
        CoordinationStatus $status,
    ): string {
        if ($status->drainingEpoch() === null) {
            return 'No coordinated writer drain is active.';
        }

        if ($status->blocker() === CoordinationBlocker::DrainingWriters) {
            return 'The coordinated workflow is waiting for persisted active-writer count to drain.';
        }

        return 'The active coordinated drain currently has no persisted writer blocker.';
    }

    private function check(
        string $code,
        ProductionSafetyCheckStatus $status,
        string $message,
    ): ProductionSafetyCheck {
        return new ProductionSafetyCheck(
            $code,
            $status,
            $message,
        );
    }
}
