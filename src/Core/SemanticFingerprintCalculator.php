<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final class SemanticFingerprintCalculator
{
    private const string NORMALIZATION_DOMAIN = "laravel-bloom-gate\0semantic-fingerprint\0normalization\0v1\0";

    private const string AUTHORITATIVE_SET_DOMAIN = "laravel-bloom-gate\0semantic-fingerprint\0authoritative-set\0v1\0";

    private const string CONSISTENCY_DOMAIN = "laravel-bloom-gate\0semantic-fingerprint\0consistency\0v1\0";

    public function normalization(
        NormalizationIdentity $identity,
    ): NormalizationFingerprint {
        return NormalizationFingerprint::fromString(
            $this->fingerprint(self::NORMALIZATION_DOMAIN, $identity->value()),
        );
    }

    public function authoritativeSet(
        AuthoritativeSetIdentity $identity,
    ): AuthoritativeSetFingerprint {
        return AuthoritativeSetFingerprint::fromString(
            $this->fingerprint(self::AUTHORITATIVE_SET_DOMAIN, $identity->value()),
        );
    }

    public function consistency(
        ConsistencyContract $contract,
    ): ConsistencyFingerprint {
        return ConsistencyFingerprint::fromString(
            $this->fingerprint(self::CONSISTENCY_DOMAIN, $contract->value),
        );
    }

    private function fingerprint(
        string $domain,
        string $identity,
    ): string {
        return 'sha256:'.hash('sha256', $domain.$identity);
    }
}
