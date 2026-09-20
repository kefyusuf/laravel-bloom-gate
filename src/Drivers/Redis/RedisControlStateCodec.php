<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Throwable;

final class RedisControlStateCodec
{
    public const string FORMAT = 'control-v1';

    private const string FIELD_FORMAT = 'format';

    private const string FIELD_REVISION = 'revision';

    private const string FIELD_LAST_ALLOCATED_VERSION = 'last_allocated_version';

    private const string FIELD_ACTIVE_VERSION = 'active_version';

    private const string FIELD_CANDIDATE_VERSION = 'candidate_version';

    /**
     * @return list<string>
     */
    public function encode(FilterControlState $state): array
    {
        $encoded = [
            self::FIELD_FORMAT,
            self::FORMAT,
            self::FIELD_REVISION,
            (string) $state->revision()->value(),
            self::FIELD_LAST_ALLOCATED_VERSION,
            (string) $state->lastAllocatedVersion()->value(),
        ];

        if ($state->activeVersion() !== null) {
            $encoded[] = self::FIELD_ACTIVE_VERSION;
            $encoded[] = (string) $state->activeVersion()->value();
        }

        if ($state->candidateVersion() !== null) {
            $encoded[] = self::FIELD_CANDIDATE_VERSION;
            $encoded[] = (string) $state->candidateVersion()->value();
        }

        $generations = $state->generations();

        usort(
            $generations,
            static fn (GenerationControlState $left, GenerationControlState $right): int =>
                $left->version()->value() <=> $right->version()->value(),
        );

        foreach ($generations as $generation) {
            $version = (string) $generation->version()->value();

            $encoded[] = sprintf('g:%s:lifecycle', $version);
            $encoded[] = $this->encodeLifecycle($generation->lifecycle());
            $encoded[] = sprintf('g:%s:health', $version);
            $encoded[] = $this->encodeHealth($generation->health());
        }

        return $encoded;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function decode(
        FilterName $name,
        array $payload,
    ): FilterControlState {
        if (array_is_list($payload) === false || count($payload) % 2 !== 0) {
            throw $this->corrupt('Redis control state must be a flat field/value list.');
        }

        /** @var array<string, string> $topLevel */
        $topLevel = [];

        /** @var array<int, array{lifecycle?: string, health?: string}> $generationFields */
        $generationFields = [];

        /** @var array<string, true> $seenFields */
        $seenFields = [];

        for ($index = 0; $index < count($payload); $index += 2) {
            $field = $payload[$index];
            $value = $payload[$index + 1];

            if (is_string($field) === false || is_string($value) === false) {
                throw $this->corrupt('Redis control state fields and values must be strings.');
            }

            if (isset($seenFields[$field])) {
                throw $this->corrupt(sprintf(
                    'Redis control state field [%s] is duplicated.',
                    $field,
                ));
            }

            $seenFields[$field] = true;

            if ($this->isTopLevelField($field)) {
                $topLevel[$field] = $value;

                continue;
            }

            if (preg_match('/\Ag:([^:]+):(lifecycle|health)\z/', $field, $matches) !== 1) {
                throw $this->corrupt(sprintf(
                    'Redis control state field [%s] is unknown or malformed.',
                    $field,
                ));
            }

            $version = $this->parseCanonicalPositiveInt(
                $matches[1],
                'generation version',
            );
            $property = $matches[2];

            $generationFields[$version][$property] = $value;
        }

        foreach ([
            self::FIELD_FORMAT,
            self::FIELD_REVISION,
            self::FIELD_LAST_ALLOCATED_VERSION,
        ] as $requiredField) {
            if (array_key_exists($requiredField, $topLevel) === false) {
                throw $this->corrupt(sprintf(
                    'Redis control state field [%s] is required.',
                    $requiredField,
                ));
            }
        }

        if ($topLevel[self::FIELD_FORMAT] !== self::FORMAT) {
            throw $this->corrupt('Redis control state format is unknown.');
        }

        $revision = $this->parseCanonicalPositiveInt(
            $topLevel[self::FIELD_REVISION],
            self::FIELD_REVISION,
        );
        $lastAllocatedVersion = $this->parseCanonicalPositiveInt(
            $topLevel[self::FIELD_LAST_ALLOCATED_VERSION],
            self::FIELD_LAST_ALLOCATED_VERSION,
        );

        $activeVersion = null;
        if (array_key_exists(self::FIELD_ACTIVE_VERSION, $topLevel)) {
            $activeVersion = $this->parseCanonicalPositiveInt(
                $topLevel[self::FIELD_ACTIVE_VERSION],
                self::FIELD_ACTIVE_VERSION,
            );
        }

        $candidateVersion = null;
        if (array_key_exists(self::FIELD_CANDIDATE_VERSION, $topLevel)) {
            $candidateVersion = $this->parseCanonicalPositiveInt(
                $topLevel[self::FIELD_CANDIDATE_VERSION],
                self::FIELD_CANDIDATE_VERSION,
            );
        }

        ksort($generationFields, SORT_NUMERIC);

        $generations = [];

        foreach ($generationFields as $version => $fields) {
            if (
                array_key_exists('lifecycle', $fields) === false
                || array_key_exists('health', $fields) === false
            ) {
                throw $this->corrupt(sprintf(
                    'Redis control generation [%d] must contain lifecycle and health.',
                    $version,
                ));
            }

            $generations[] = new GenerationControlState(
                version: FilterVersion::fromInt($version),
                lifecycle: $this->decodeLifecycle($fields['lifecycle']),
                health: $this->decodeHealth($fields['health']),
            );
        }

        try {
            return new FilterControlState(
                filterName: $name,
                revision: FilterStateRevision::fromInt($revision),
                lastAllocatedVersion: FilterVersion::fromInt($lastAllocatedVersion),
                activeVersion: $activeVersion === null
                    ? null
                    : FilterVersion::fromInt($activeVersion),
                candidateVersion: $candidateVersion === null
                    ? null
                    : FilterVersion::fromInt($candidateVersion),
                generations: $generations,
            );
        } catch (InvalidArgumentException $failure) {
            throw $this->corrupt(
                'Redis control state violates Core invariants.',
                $failure,
            );
        }
    }

    private function isTopLevelField(string $field): bool
    {
        return in_array($field, [
            self::FIELD_FORMAT,
            self::FIELD_REVISION,
            self::FIELD_LAST_ALLOCATED_VERSION,
            self::FIELD_ACTIVE_VERSION,
            self::FIELD_CANDIDATE_VERSION,
        ], true);
    }

    private function parseCanonicalPositiveInt(
        string $value,
        string $field,
    ): int {
        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw $this->corrupt(sprintf(
                'Redis control state [%s] must be a canonical positive decimal.',
                $field,
            ));
        }

        $parsed = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($parsed === false || (string) $parsed !== $value) {
            throw $this->corrupt(sprintf(
                'Redis control state [%s] is outside the supported integer range.',
                $field,
            ));
        }

        return $parsed;
    }

    private function encodeLifecycle(LifecycleState $lifecycle): string
    {
        return match ($lifecycle) {
            LifecycleState::Configured => 'configured',
            LifecycleState::Building => 'building',
            LifecycleState::Shadow => 'shadow',
            LifecycleState::Verified => 'verified',
            LifecycleState::Active => 'active',
            LifecycleState::Retired => 'retired',
        };
    }

    private function decodeLifecycle(string $token): LifecycleState
    {
        return match ($token) {
            'configured' => LifecycleState::Configured,
            'building' => LifecycleState::Building,
            'shadow' => LifecycleState::Shadow,
            'verified' => LifecycleState::Verified,
            'active' => LifecycleState::Active,
            'retired' => LifecycleState::Retired,
            default => throw $this->corrupt(sprintf(
                'Redis control state lifecycle token [%s] is unknown.',
                $token,
            )),
        };
    }

    private function encodeHealth(HealthState $health): string
    {
        return match ($health) {
            HealthState::Healthy => 'healthy',
            HealthState::Degraded => 'degraded',
            HealthState::Stale => 'stale',
            HealthState::Unavailable => 'unavailable',
        };
    }

    private function decodeHealth(string $token): HealthState
    {
        return match ($token) {
            'healthy' => HealthState::Healthy,
            'degraded' => HealthState::Degraded,
            'stale' => HealthState::Stale,
            'unavailable' => HealthState::Unavailable,
            default => throw $this->corrupt(sprintf(
                'Redis control state health token [%s] is unknown.',
                $token,
            )),
        };
    }

    private function corrupt(
        string $message,
        ?Throwable $previous = null,
    ): FilterControlStateCorrupt {
        return new FilterControlStateCorrupt(
            $message,
            0,
            $previous,
        );
    }
}
