<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class BypassReason
{
    private const MAX_BYTES = 64;

    private const PATTERN = '/\A[a-z][a-z0-9._-]{0,63}\z/';

    private function __construct(
        private string $code,
    ) {}

    public static function fromCode(string $code): self
    {
        $length = strlen($code);

        if ($length < 1 || $length > self::MAX_BYTES || preg_match(self::PATTERN, $code) !== 1) {
            throw new InvalidArgumentException(
                'Bypass reason must contain 1 to 64 ASCII characters, start with a lowercase letter, and use only lowercase letters, digits, dot, underscore, or hyphen.',
            );
        }

        return new self($code);
    }

    public static function optimizationDisabled(): self
    {
        return self::fromCode('optimization_disabled');
    }

    public static function lifecycleNotActive(): self
    {
        return self::fromCode('lifecycle_not_active');
    }

    public static function healthNotHealthy(): self
    {
        return self::fromCode('health_not_healthy');
    }

    public static function activeVersionUnavailable(): self
    {
        return self::fromCode('active_version_unavailable');
    }

    public static function backendUnavailable(): self
    {
        return self::fromCode('backend_unavailable');
    }

    public static function backendProfileUnasserted(): self
    {
        return self::fromCode('backend_profile_unasserted');
    }

    public static function operationFailed(): self
    {
        return self::fromCode('operation_failed');
    }

    public static function generationContractUnbound(): self
    {
        return self::fromCode('generation_contract_unbound');
    }

    public static function normalizationMismatch(): self
    {
        return self::fromCode('normalization_mismatch');
    }

    public static function authoritativeSetMismatch(): self
    {
        return self::fromCode('authoritative_set_mismatch');
    }

    public static function consistencyMismatch(): self
    {
        return self::fromCode('consistency_mismatch');
    }

    public static function controlStateChanged(): self
    {
        return self::fromCode('control_state_changed');
    }

    public static function generationStorageUnavailable(): self
    {
        return self::fromCode('generation_storage_unavailable');
    }

    public static function generationStorageCorrupt(): self
    {
        return self::fromCode('generation_storage_corrupt');
    }

    public function code(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
