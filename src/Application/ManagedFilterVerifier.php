<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationEvidenceApplier;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationResult;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;

final readonly class ManagedFilterVerifier
{
    public function __construct(
        private FilterRegistry $registry,
        private FilterControlStore $control,
        private GenerationContractStore $generationContracts,
        private ActivationVerifier $activationVerifier,
        private ActivationVerificationEvidenceApplier $evidenceApplier,
        private GenerationHealthUpdater $health,
        private SemanticFingerprintCalculator $fingerprints,
    ) {}

    public function verify(FilterName $name): ActivationVerificationResult
    {
        return $this->verifyCandidate($name, false);
    }

    public function verifyForActivation(
        FilterName $name,
    ): ActivationVerificationResult {
        return $this->verifyCandidate($name, true);
    }

    private function verifyCandidate(
        FilterName $name,
        bool $allowAlreadyVerified,
    ): ActivationVerificationResult {
        $current = $this->requireControlState($name);
        $candidateVersion = $this->requireCandidateVersion($current);
        $candidate = $this->requireCandidateGeneration(
            $current,
            $candidateVersion,
        );

        $allowedLifecycle = $candidate->lifecycle() === LifecycleState::Shadow
            || (
                $allowAlreadyVerified
                && $candidate->lifecycle() === LifecycleState::Verified
            );

        if ($allowedLifecycle === false) {
            throw new InvalidArgumentException(
                'Managed verification requires a SHADOW candidate, or VERIFIED only during activation.',
            );
        }

        if ($candidate->health() !== HealthState::Healthy) {
            throw new InvalidArgumentException(
                'Managed verification requires a HEALTHY candidate generation.',
            );
        }

        $registered = $this->registry->get($name);
        $definition = $registered->definition();
        $normalizer = $definition->normalizer();
        $authoritativeSet = $definition->authoritativeSet();
        $descriptor = $this->generationContracts->read(
            $name,
            $candidateVersion,
        );

        if ($descriptor === null) {
            throw new InvalidArgumentException(
                'Managed verification requires bound generation semantics.',
            );
        }

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
                'Managed verification runtime semantics do not match the candidate generation.',
            );
        }

        $result = $this->activationVerifier->verify(
            $name,
            $candidateVersion,
            $descriptor->layout(),
            $this->normalizedValues($authoritativeSet, $normalizer),
        );

        if ($result->status() === ActivationVerificationStatus::FalseNegativeDetected) {
            $this->health->update(
                $name,
                $candidateVersion,
                HealthState::Stale,
            );

            return $result;
        }

        if ($candidate->lifecycle() === LifecycleState::Verified) {
            return $result;
        }

        $next = $this->evidenceApplier->apply(
            $current,
            $result,
        );

        $this->control->compareAndSwap(
            $name,
            $next,
            $current->revision(),
        );

        return $result;
    }

    private function requireControlState(
        FilterName $name,
    ): FilterControlState {
        $state = $this->control->read($name);

        if ($state === null) {
            throw new InvalidArgumentException(
                'Managed verification requires filter control state.',
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
                'Managed verification requires a current candidate generation.',
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
}
