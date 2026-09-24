<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;

final readonly class QuerySafetyDescriptorResolver
{
    public function __construct(
        private ActiveGenerationSnapshotReader $snapshots,
        private GenerationContractStore $contracts,
    ) {}

    public function resolve(
        FilterName $name,
        GenerationSemanticContract $expectedContract,
    ): QuerySafetyDescriptorResolution {
        try {
            $snapshot = $this->snapshots->readActive($name);
        } catch (FilterControlStoreOperationFailed) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::backendUnavailable(),
            );
        } catch (FilterControlStateCorrupt) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::operationFailed(),
            );
        }

        if ($snapshot === null) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::activeVersionUnavailable(),
            );
        }

        if ($snapshot->filterName()->equals($name) === false) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::operationFailed(),
            );
        }

        if ($snapshot->lifecycle() !== LifecycleState::Active) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::lifecycleNotActive(),
            );
        }

        if ($snapshot->health() !== HealthState::Healthy) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::healthNotHealthy(),
            );
        }

        try {
            $managed = $this->contracts->read(
                $name,
                $snapshot->activeVersion(),
            );
        } catch (BloomFilterNotProvisioned) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::generationStorageUnavailable(),
            );
        } catch (BloomStorageCorrupt|BloomLayoutMismatch) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::generationStorageCorrupt(),
            );
        } catch (GenerationContractStoreOperationFailed) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::backendUnavailable(),
            );
        }

        if ($managed === null) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::generationContractUnbound(),
            );
        }

        $persisted = $managed->semanticContract();

        if (
            $persisted->normalizationFingerprint()
                ->equals($expectedContract->normalizationFingerprint()) === false
        ) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::normalizationMismatch(),
            );
        }

        if (
            $persisted->authoritativeSetFingerprint()
                ->equals($expectedContract->authoritativeSetFingerprint()) === false
        ) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::authoritativeSetMismatch(),
            );
        }

        if (
            $persisted->consistencyFingerprint()
                ->equals($expectedContract->consistencyFingerprint()) === false
        ) {
            return QuerySafetyDescriptorResolution::bypassed(
                BypassReason::consistencyMismatch(),
            );
        }

        return QuerySafetyDescriptorResolution::ready(
            new QuerySafetyDescriptor(
                filterName: $name,
                revision: $snapshot->revision(),
                activeVersion: $snapshot->activeVersion(),
                layout: $managed->layout(),
                semanticContract: $expectedContract,
            ),
        );
    }
}
