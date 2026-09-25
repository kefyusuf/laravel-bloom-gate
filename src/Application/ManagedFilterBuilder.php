<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Lifecycle\CandidateAllocator;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use LogicException;

final readonly class ManagedFilterBuilder
{
    public function __construct(
        private FilterRegistry $registry,
        private OptimalBloomSizingV1 $sizing,
        private CandidateAllocator $allocator,
        private GenerationLifecycleTransitioner $transitions,
        private GenerationHealthUpdater $health,
        private BulkBloomDriver $driver,
        private GenerationContractStore $generationContracts,
        private BloomProbeGenerator $probes,
        private SemanticFingerprintCalculator $fingerprints,
        private int $chunkSize,
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException(
                'Managed Bloom build chunk size must be at least 1.',
            );
        }
    }

    public function build(FilterName $name): FilterControlState
    {
        return $this->buildResult($name)->state();
    }

    public function buildResult(FilterName $name): ManagedFilterBuildResult
    {
        $registered = $this->registry->get($name);
        $definition = $registered->definition();

        $layout = $this->sizing->layout(
            $registered->capacity(),
            $registered->falsePositiveRate(),
        );

        $normalizer = $definition->normalizer();
        $authoritativeSet = $definition->authoritativeSet();
        $semanticContract = new GenerationSemanticContract(
            normalizationFingerprint: $this->fingerprints->normalization(
                $normalizer->identity(),
            ),
            authoritativeSetFingerprint: $this->fingerprints->authoritativeSet(
                $authoritativeSet->identity(),
            ),
            consistencyFingerprint: $this->fingerprints->consistency(
                $definition->consistency(),
            ),
        );

        $allocated = $this->allocator->allocate($name);
        $candidateVersion = $allocated->candidateVersion();

        if ($candidateVersion === null) {
            throw new LogicException(
                'Candidate allocator returned control state without a candidate generation.',
            );
        }

        $this->transitions->transition(
            $name,
            $candidateVersion,
            LifecycleState::Building,
        );

        $this->driver->provision(
            $name,
            $candidateVersion,
            $layout,
        );

        $this->generationContracts->bind(
            $name,
            $candidateVersion,
            $layout,
            $semanticContract,
        );

        /** @var list<BitPositions> $batch */
        $batch = [];
        $processedCount = 0;

        foreach ($authoritativeSet->values() as $value) {
            $processedCount++;
            $batch[] = $this->probes->generate(
                $normalizer->normalize($value),
                $layout,
            );

            if (count($batch) !== $this->chunkSize) {
                continue;
            }

            $this->driver->addMany(
                $name,
                $candidateVersion,
                $batch,
            );
            $batch = [];
        }

        if ($batch !== []) {
            $this->driver->addMany(
                $name,
                $candidateVersion,
                $batch,
            );
        }

        $this->health->update(
            $name,
            $candidateVersion,
            HealthState::Healthy,
        );

        $state = $this->transitions->transition(
            $name,
            $candidateVersion,
            LifecycleState::Shadow,
        );

        return new ManagedFilterBuildResult(
            state: $state,
            layout: $layout,
            processedCount: $processedCount,
        );
    }
}
