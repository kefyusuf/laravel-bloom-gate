<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use LogicException;

final readonly class QueryGate
{
    public function __construct(
        private FilterRegistry $registry,
        private QuerySafetyDescriptorResolver $resolver,
        private AuthorizedProbe $authorizedProbe,
        private BloomProbeGenerator $probes,
        private SemanticFingerprintCalculator $fingerprints,
    ) {}

    public function exists(
        string $filter,
        string|int $value,
    ): bool {
        return $this->existsResult($filter, $value)->exists();
    }

    public function existsResult(
        string $filter,
        string|int $value,
    ): ExistenceResult {
        $name = FilterName::fromString($filter);
        $registered = $this->registry->get($name);
        $definition = $registered->definition();
        $normalizer = $definition->normalizer();
        $authoritativeSet = $definition->authoritativeSet();
        $normalized = $normalizer->normalize($value);

        if (
            $this->registry->globalQueryOptimizationEnabled() === false
            || $registered->queryOptimizationEnabled() === false
        ) {
            return $this->authoritativeBypass(
                $authoritativeSet,
                $normalized,
                BypassReason::optimizationDisabled(),
            );
        }

        $expectedContract = new GenerationSemanticContract(
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

        $resolution = $this->resolver->resolve(
            $name,
            $expectedContract,
        );
        $bypassReason = $resolution->bypassReason();

        if ($bypassReason !== null) {
            return $this->authoritativeBypass(
                $authoritativeSet,
                $normalized,
                $bypassReason,
            );
        }

        $descriptor = $resolution->descriptor();

        if ($descriptor === null) {
            throw new LogicException(
                'Query safety resolution returned neither descriptor nor bypass reason.',
            );
        }

        $positions = $this->probes->generate(
            $normalized,
            $descriptor->layout(),
        );

        $probeResult = $this->authorizedProbe->probe(
            $descriptor,
            $positions,
        );

        return match ($probeResult->membership()) {
            Membership::DefinitelyAbsent => ExistenceResult::definitelyAbsent(),
            Membership::MaybePresent => ExistenceResult::maybePresent(
                $authoritativeSet->exists($normalized),
            ),
            Membership::Bypassed => $this->authorizedProbeBypass(
                $probeResult,
                $authoritativeSet,
                $normalized,
            ),
        };
    }

    private function authoritativeBypass(
        AuthoritativeSet $authoritativeSet,
        NormalizedValue $value,
        BypassReason $reason,
    ): ExistenceResult {
        return ExistenceResult::bypassed(
            exists: $authoritativeSet->exists($value),
            reason: $reason,
        );
    }

    private function authorizedProbeBypass(
        AuthorizedProbeResult $probeResult,
        AuthoritativeSet $authoritativeSet,
        NormalizedValue $value,
    ): ExistenceResult {
        $reason = $probeResult->bypassReason();

        if ($reason === null) {
            throw new LogicException(
                'Authorized probe returned BYPASSED membership without a bypass reason.',
            );
        }

        return $this->authoritativeBypass(
            $authoritativeSet,
            $value,
            $reason,
        );
    }
}
