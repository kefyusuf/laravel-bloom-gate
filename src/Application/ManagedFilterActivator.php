<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;

final readonly class ManagedFilterActivator
{
    public function __construct(
        private FilterRegistry $registry,
        private FilterControlStore $control,
        private GenerationContractStore $generationContracts,
        private BulkBloomDriver $driver,
        private BloomProbeGenerator $probes,
        private SemanticFingerprintCalculator $fingerprints,
        private ManagedFilterVerifier $verifier,
        private CandidatePromoter $promoter,
        private int $chunkSize,
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException(
                'Managed Bloom activation chunk size must be at least 1.',
            );
        }
    }

    public function activate(
        FilterName $name,
        bool $quiescent = false,
    ): FilterControlState {
        $registered = $this->registry->get($name);
        $definition = $registered->definition();
        $consistency = $definition->consistency();

        if (
            $consistency === ConsistencyContract::PreAddV1
            && $quiescent === false
        ) {
            throw new InvalidArgumentException(
                'preadd-v1 activation requires an explicit quiescent membership-entry window.',
            );
        }

        if ($consistency === ConsistencyContract::PreAddV1) {
            $this->reconcile($name, $definition);
        }

        $verification = $this->verifier->verifyForActivation($name);

        if ($verification->status() !== ActivationVerificationStatus::Passed) {
            throw new InvalidArgumentException(
                'Candidate activation is blocked because fresh verification detected a false negative.',
            );
        }

        $state = $this->requireControlState($name);
        $candidateVersion = $state->candidateVersion();

        if ($candidateVersion === null) {
            throw new InvalidArgumentException(
                'Managed activation requires a current candidate generation.',
            );
        }

        return $this->promoter->promote(
            $name,
            $candidateVersion,
        );
    }

    private function reconcile(
        FilterName $name,
        FilterDefinition $definition,
    ): void {
        $state = $this->requireControlState($name);
        $candidateVersion = $this->requireCandidateVersion($state);
        $candidate = $this->requireCandidateGeneration(
            $state,
            $candidateVersion,
        );

        if (
            $candidate->lifecycle() !== LifecycleState::Shadow
            && $candidate->lifecycle() !== LifecycleState::Verified
        ) {
            throw new InvalidArgumentException(
                'Managed reconciliation requires a SHADOW or VERIFIED candidate.',
            );
        }

        if ($candidate->health() !== HealthState::Healthy) {
            throw new InvalidArgumentException(
                'Managed reconciliation requires a HEALTHY candidate.',
            );
        }

        $descriptor = $this->generationContracts->read(
            $name,
            $candidateVersion,
        );

        if ($descriptor === null) {
            throw new InvalidArgumentException(
                'Managed reconciliation requires bound generation semantics.',
            );
        }

        $normalizer = $definition->normalizer();
        $authoritativeSet = $definition->authoritativeSet();
        $expected = new GenerationSemanticContract(
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

        if ($descriptor->semanticContract()->equals($expected) === false) {
            throw new InvalidArgumentException(
                'Managed reconciliation runtime semantics do not match the candidate generation.',
            );
        }

        /** @var list<BitPositions> $batch */
        $batch = [];

        foreach ($authoritativeSet->values() as $value) {
            $batch[] = $this->probes->generate(
                $normalizer->normalize($value),
                $descriptor->layout(),
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
    }

    private function requireControlState(
        FilterName $name,
    ): FilterControlState {
        $state = $this->control->read($name);

        if ($state === null) {
            throw new InvalidArgumentException(
                'Managed activation requires filter control state.',
            );
        }

        return $state;
    }

    private function requireCandidateVersion(
        FilterControlState $state,
    ): FilterVersion {
        $candidate = $state->candidateVersion();

        if ($candidate === null) {
            throw new InvalidArgumentException(
                'Managed activation requires a current candidate generation.',
            );
        }

        return $candidate;
    }

    private function requireCandidateGeneration(
        FilterControlState $state,
        FilterVersion $version,
    ): GenerationControlState {
        foreach ($state->generations() as $generation) {
            if ($generation->version()->equals($version)) {
                return $generation;
            }
        }

        throw new InvalidArgumentException(
            'Current candidate generation is not tracked by control state.',
        );
    }
}
