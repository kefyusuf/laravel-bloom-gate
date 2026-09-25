<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownFilter;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use LogicException;

final readonly class ManagedFilterStatusReader
{
    public function __construct(
        private FilterRegistry $registry,
        private FilterControlStore $control,
        private BloomGenerationInspector $inspector,
        private GenerationContractStore $contracts,
        private SemanticFingerprintCalculator $fingerprints,
    ) {}

    public function read(FilterName $name): ManagedFilterStatus
    {
        $registered = true;
        $filterEnabled = null;
        $consistency = null;
        $expectedContract = null;

        try {
            $filter = $this->registry->get($name);
            $definition = $filter->definition();
            $filterEnabled = $filter->queryOptimizationEnabled();
            $consistency = $definition->consistency();
            $expectedContract = new GenerationSemanticContract(
                normalizationFingerprint: $this->fingerprints->normalization(
                    $definition->normalizer()->identity(),
                ),
                authoritativeSetFingerprint: $this->fingerprints->authoritativeSet(
                    $definition->authoritativeSet()->identity(),
                ),
                consistencyFingerprint: $this->fingerprints->consistency(
                    $consistency,
                ),
            );
        } catch (UnknownFilter) {
            $registered = false;
        }

        $state = $this->control->read($name);

        return new ManagedFilterStatus(
            name: $name,
            registered: $registered,
            globalQueryOptimizationEnabled: $this->registry
                ->globalQueryOptimizationEnabled(),
            queryOptimizationEnabled: $filterEnabled,
            consistency: $consistency,
            active: $this->generationStatus(
                $name,
                $state,
                $state?->activeVersion(),
                'active',
                $expectedContract,
            ),
            candidate: $this->generationStatus(
                $name,
                $state,
                $state?->candidateVersion(),
                'candidate',
                $expectedContract,
            ),
        );
    }

    private function generationStatus(
        FilterName $name,
        ?FilterControlState $state,
        ?FilterVersion $version,
        string $slot,
        ?GenerationSemanticContract $expectedContract,
    ): ?ManagedGenerationStatus {
        if ($state === null || $version === null) {
            return null;
        }

        $generation = $this->generation($state, $version);

        try {
            $layout = $this->inspector->layout($name, $version);
        } catch (BloomFilterNotProvisioned) {
            $layout = null;
        }

        try {
            $descriptor = $this->contracts->read($name, $version);
        } catch (BloomFilterNotProvisioned) {
            $descriptor = null;
        }

        if ($layout === null && $descriptor !== null) {
            $layout = $descriptor->layout();
        }

        return new ManagedGenerationStatus(
            slot: $slot,
            version: $version,
            lifecycle: $generation->lifecycle(),
            health: $generation->health(),
            layout: $layout,
            semanticBound: $descriptor !== null,
            semanticMatches: $descriptor === null || $expectedContract === null
                ? null
                : $descriptor->semanticContract()->equals($expectedContract),
        );
    }

    private function generation(
        FilterControlState $state,
        FilterVersion $version,
    ): GenerationControlState {
        foreach ($state->generations() as $generation) {
            if ($generation->version()->equals($version)) {
                return $generation;
            }
        }

        throw new LogicException(
            'Managed status pointer references an untracked generation.',
        );
    }
}
