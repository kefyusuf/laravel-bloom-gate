<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;

final readonly class MemoryAuthorizedProbe implements AuthorizedProbe
{
    public function __construct(
        private ActiveGenerationSnapshotReader $snapshots,
        private GenerationContractStore $contracts,
        private BloomDriver $driver,
    ) {}

    public function probe(
        QuerySafetyDescriptor $descriptor,
        BitPositions $positions,
    ): AuthorizedProbeResult {
        if ($descriptor->layout()->equals($positions->layout()) === false) {
            throw new BloomLayoutMismatch(
                'Authorized probe positions do not match the pinned generation layout.',
            );
        }

        try {
            $snapshot = $this->snapshots->readActive($descriptor->filterName());
        } catch (FilterControlStoreOperationFailed) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::backendUnavailable(),
            );
        } catch (FilterControlStateCorrupt) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::operationFailed(),
            );
        }

        if (
            $snapshot === null
            || $snapshot->filterName()->equals($descriptor->filterName()) === false
            || $snapshot->revision()->equals($descriptor->revision()) === false
            || $snapshot->activeVersion()->equals($descriptor->activeVersion()) === false
            || $snapshot->lifecycle() !== LifecycleState::Active
            || $snapshot->health() !== HealthState::Healthy
        ) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::controlStateChanged(),
            );
        }

        try {
            $managed = $this->contracts->read(
                $descriptor->filterName(),
                $descriptor->activeVersion(),
            );
        } catch (BloomFilterNotProvisioned) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::generationStorageUnavailable(),
            );
        } catch (BloomStorageCorrupt|BloomLayoutMismatch) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::generationStorageCorrupt(),
            );
        } catch (GenerationContractStoreOperationFailed) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::backendUnavailable(),
            );
        }

        if ($managed === null) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::generationContractUnbound(),
            );
        }

        if ($managed->layout()->equals($descriptor->layout()) === false) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::generationStorageCorrupt(),
            );
        }

        $semanticMismatch = $this->semanticMismatch(
            $managed->semanticContract(),
            $descriptor->semanticContract(),
        );

        if ($semanticMismatch !== null) {
            return AuthorizedProbeResult::bypassed($semanticMismatch);
        }

        try {
            $maybePresent = $this->driver->mightContain(
                $descriptor->filterName(),
                $descriptor->activeVersion(),
                $positions,
            );
        } catch (BloomFilterNotProvisioned) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::generationStorageUnavailable(),
            );
        } catch (BloomStorageCorrupt) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::generationStorageCorrupt(),
            );
        } catch (BloomDriverOperationFailed) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::backendUnavailable(),
            );
        }

        return $maybePresent
            ? AuthorizedProbeResult::maybePresent()
            : AuthorizedProbeResult::definitelyAbsent();
    }

    private function semanticMismatch(
        GenerationSemanticContract $persisted,
        GenerationSemanticContract $expected,
    ): ?BypassReason {
        if (
            $persisted->normalizationFingerprint()
                ->equals($expected->normalizationFingerprint()) === false
        ) {
            return BypassReason::normalizationMismatch();
        }

        if (
            $persisted->authoritativeSetFingerprint()
                ->equals($expected->authoritativeSetFingerprint()) === false
        ) {
            return BypassReason::authoritativeSetMismatch();
        }

        if (
            $persisted->consistencyFingerprint()
                ->equals($expected->consistencyFingerprint()) === false
        ) {
            return BypassReason::consistencyMismatch();
        }

        return null;
    }
}
