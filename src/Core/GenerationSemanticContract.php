<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class GenerationSemanticContract
{
    public function __construct(
        private NormalizationFingerprint $normalizationFingerprint,
        private AuthoritativeSetFingerprint $authoritativeSetFingerprint,
        private ConsistencyFingerprint $consistencyFingerprint,
    ) {}

    public function normalizationFingerprint(): NormalizationFingerprint
    {
        return $this->normalizationFingerprint;
    }

    public function authoritativeSetFingerprint(): AuthoritativeSetFingerprint
    {
        return $this->authoritativeSetFingerprint;
    }

    public function consistencyFingerprint(): ConsistencyFingerprint
    {
        return $this->consistencyFingerprint;
    }

    public function equals(self $other): bool
    {
        return $this->normalizationFingerprint->equals($other->normalizationFingerprint)
            && $this->authoritativeSetFingerprint->equals($other->authoritativeSetFingerprint)
            && $this->consistencyFingerprint->equals($other->consistencyFingerprint);
    }
}
