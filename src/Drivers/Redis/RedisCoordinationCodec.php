<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Throwable;

final class RedisCoordinationCodec
{
    public const string FORMAT = 'sync-v1';

    public const string OWNER_VALUE = 'coordinated-v1';

    private const string FIELD_FORMAT = 'format';

    private const string FIELD_REVISION = 'revision';

    private const string FIELD_PHASE = 'phase';

    private const string FIELD_CURRENT_EPOCH = 'current_epoch';

    private const string FIELD_CURRENT_TARGETS = 'current_targets';

    private const string FIELD_CANDIDATE_VERSION = 'candidate_version';

    private const string FIELD_DRAINING_EPOCH = 'draining_epoch';

    /**
     * @return list<string>
     */
    public function encodeSynchronization(
        SynchronizationState $state,
    ): array {
        $encoded = [
            self::FIELD_FORMAT,
            self::FORMAT,
            self::FIELD_REVISION,
            (string) $state->revision()->value(),
            self::FIELD_PHASE,
            $this->encodePhase($state->phase()),
            self::FIELD_CURRENT_EPOCH,
            (string) $state->currentEpoch()->value(),
            self::FIELD_CURRENT_TARGETS,
            $this->encodeTargets($state->currentTargets()),
        ];

        if ($state->candidateVersion() !== null) {
            $encoded[] = self::FIELD_CANDIDATE_VERSION;
            $encoded[] = (string) $state->candidateVersion()->value();
        }

        if ($state->drainingEpoch() !== null) {
            $encoded[] = self::FIELD_DRAINING_EPOCH;
            $encoded[] = (string) $state->drainingEpoch()->value();
        }

        return $encoded;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function decodeSynchronization(
        array $payload,
    ): SynchronizationState {
        if (array_is_list($payload) === false || count($payload) % 2 !== 0) {
            throw $this->corrupt(
                'Redis synchronization state must be a flat field/value list.',
            );
        }

        /** @var array<string, string> $fields */
        $fields = [];

        for ($index = 0; $index < count($payload); $index += 2) {
            $field = $payload[$index];
            $value = $payload[$index + 1];

            if (! is_string($field) || ! is_string($value)) {
                throw $this->corrupt(
                    'Redis synchronization fields and values must be strings.',
                );
            }

            if (! $this->isSynchronizationField($field)) {
                throw $this->corrupt(sprintf(
                    'Redis synchronization field [%s] is unknown.',
                    $field,
                ));
            }

            if (array_key_exists($field, $fields)) {
                throw $this->corrupt(sprintf(
                    'Redis synchronization field [%s] is duplicated.',
                    $field,
                ));
            }

            $fields[$field] = $value;
        }

        foreach ([
            self::FIELD_FORMAT,
            self::FIELD_REVISION,
            self::FIELD_PHASE,
            self::FIELD_CURRENT_EPOCH,
            self::FIELD_CURRENT_TARGETS,
        ] as $required) {
            if (! array_key_exists($required, $fields)) {
                throw $this->corrupt(sprintf(
                    'Redis synchronization field [%s] is required.',
                    $required,
                ));
            }
        }

        if ($fields[self::FIELD_FORMAT] !== self::FORMAT) {
            throw $this->corrupt('Redis synchronization format is unknown.');
        }

        $candidateVersion = null;
        if (array_key_exists(self::FIELD_CANDIDATE_VERSION, $fields)) {
            $candidateVersion = FilterVersion::fromInt(
                $this->parseCanonicalPositiveInt(
                    $fields[self::FIELD_CANDIDATE_VERSION],
                    self::FIELD_CANDIDATE_VERSION,
                ),
            );
        }

        $drainingEpoch = null;
        if (array_key_exists(self::FIELD_DRAINING_EPOCH, $fields)) {
            $drainingEpoch = SynchronizationEpoch::fromInt(
                $this->parseCanonicalPositiveInt(
                    $fields[self::FIELD_DRAINING_EPOCH],
                    self::FIELD_DRAINING_EPOCH,
                ),
            );
        }

        try {
            return new SynchronizationState(
                revision: SynchronizationRevision::fromInt(
                    $this->parseCanonicalPositiveInt(
                        $fields[self::FIELD_REVISION],
                        self::FIELD_REVISION,
                    ),
                ),
                phase: $this->decodePhase($fields[self::FIELD_PHASE]),
                currentEpoch: SynchronizationEpoch::fromInt(
                    $this->parseCanonicalPositiveInt(
                        $fields[self::FIELD_CURRENT_EPOCH],
                        self::FIELD_CURRENT_EPOCH,
                    ),
                ),
                currentTargets: $this->decodeTargets(
                    $fields[self::FIELD_CURRENT_TARGETS],
                ),
                candidateVersion: $candidateVersion,
                drainingEpoch: $drainingEpoch,
            );
        } catch (InvalidArgumentException $failure) {
            throw $this->corrupt(
                'Redis synchronization state violates Core invariants.',
                $failure,
            );
        }
    }

    public function encodeLease(WriterLease $lease): string
    {
        return sprintf(
            '%s|%d|%s',
            $this->encodeLeaseState($lease->state()),
            $lease->epoch()->value(),
            $this->encodeTargets($lease->targets()),
        );
    }

    public function decodeLease(
        WriterLeaseToken $token,
        string $encoded,
    ): WriterLease {
        $parts = explode('|', $encoded);

        if (count($parts) !== 3) {
            throw $this->corrupt(
                'Redis writer lease record must contain state, epoch, and targets.',
            );
        }

        [$stateToken, $epochToken, $targetsToken] = $parts;

        try {
            return new WriterLease(
                token: $token,
                state: $this->decodeLeaseState($stateToken),
                epoch: SynchronizationEpoch::fromInt(
                    $this->parseCanonicalPositiveInt(
                        $epochToken,
                        'writer lease epoch',
                    ),
                ),
                targets: $this->decodeTargets($targetsToken),
            );
        } catch (InvalidArgumentException $failure) {
            throw $this->corrupt(
                'Redis writer lease record violates Core invariants.',
                $failure,
            );
        }
    }

    public function encodeCountField(
        SynchronizationEpoch $epoch,
    ): string {
        return sprintf('e:%d', $epoch->value());
    }

    public function decodeCountField(
        string $field,
    ): SynchronizationEpoch {
        if (preg_match('/\Ae:(.+)\z/', $field, $matches) !== 1) {
            throw $this->corrupt(
                'Redis active-writer count field is malformed.',
            );
        }

        return SynchronizationEpoch::fromInt(
            $this->parseCanonicalPositiveInt(
                $matches[1],
                'active-writer count epoch',
            ),
        );
    }

    public function encodeCount(int $count): string
    {
        if ($count < 0) {
            throw new InvalidArgumentException(
                'Active-writer count cannot be negative.',
            );
        }

        return (string) $count;
    }

    public function decodeCount(string $encoded): int
    {
        return $this->parseCanonicalNonNegativeInt(
            $encoded,
            'active-writer count',
        );
    }

    public function assertOwnerValue(string $value): void
    {
        if ($value === self::OWNER_VALUE) {
            return;
        }

        throw $this->corrupt(
            'Redis coordinated ownership marker is malformed.',
        );
    }

    private function isSynchronizationField(string $field): bool
    {
        return in_array($field, [
            self::FIELD_FORMAT,
            self::FIELD_REVISION,
            self::FIELD_PHASE,
            self::FIELD_CURRENT_EPOCH,
            self::FIELD_CURRENT_TARGETS,
            self::FIELD_CANDIDATE_VERSION,
            self::FIELD_DRAINING_EPOCH,
        ], true);
    }

    private function encodePhase(
        SynchronizationPhase $phase,
    ): string {
        return match ($phase) {
            SynchronizationPhase::Steady => 'STEADY',
            SynchronizationPhase::DrainingPreReconcile => 'DRAINING_PRE_RECONCILE',
            SynchronizationPhase::Reconciling => 'RECONCILING',
            SynchronizationPhase::ReadyToPromote => 'READY_TO_PROMOTE',
            SynchronizationPhase::DrainingPostPromotion => 'DRAINING_POST_PROMOTION',
            SynchronizationPhase::AbortRequested => 'ABORT_REQUESTED',
            SynchronizationPhase::DrainingAbort => 'DRAINING_ABORT',
        };
    }

    private function decodePhase(string $token): SynchronizationPhase
    {
        return match ($token) {
            'STEADY' => SynchronizationPhase::Steady,
            'DRAINING_PRE_RECONCILE' => SynchronizationPhase::DrainingPreReconcile,
            'RECONCILING' => SynchronizationPhase::Reconciling,
            'READY_TO_PROMOTE' => SynchronizationPhase::ReadyToPromote,
            'DRAINING_POST_PROMOTION' => SynchronizationPhase::DrainingPostPromotion,
            'ABORT_REQUESTED' => SynchronizationPhase::AbortRequested,
            'DRAINING_ABORT' => SynchronizationPhase::DrainingAbort,
            default => throw $this->corrupt(sprintf(
                'Redis synchronization phase [%s] is unknown.',
                $token,
            )),
        };
    }

    private function encodeLeaseState(
        WriterLeaseState $state,
    ): string {
        return match ($state) {
            WriterLeaseState::Acquired => 'A',
            WriterLeaseState::Prepared => 'P',
            WriterLeaseState::Released => 'R',
        };
    }

    private function decodeLeaseState(
        string $token,
    ): WriterLeaseState {
        return match ($token) {
            'A' => WriterLeaseState::Acquired,
            'P' => WriterLeaseState::Prepared,
            'R' => WriterLeaseState::Released,
            default => throw $this->corrupt(sprintf(
                'Redis writer lease state [%s] is unknown.',
                $token,
            )),
        };
    }

    private function encodeTargets(
        SynchronizationTargetSet $targets,
    ): string {
        $versions = $targets->versions();

        if ($versions === []) {
            return '-';
        }

        return implode(',', array_map(
            static fn (FilterVersion $version): string => (string) $version->value(),
            $versions,
        ));
    }

    private function decodeTargets(
        string $encoded,
    ): SynchronizationTargetSet {
        if ($encoded === '-') {
            return SynchronizationTargetSet::fromVersions([]);
        }

        if ($encoded === '') {
            throw $this->corrupt(
                'Redis synchronization targets are malformed.',
            );
        }

        $versions = [];
        $previous = null;

        foreach (explode(',', $encoded) as $token) {
            $value = $this->parseCanonicalPositiveInt(
                $token,
                'synchronization target',
            );

            if ($previous !== null && $value <= $previous) {
                throw $this->corrupt(
                    'Redis synchronization targets must be strictly ascending and unique.',
                );
            }

            $versions[] = FilterVersion::fromInt($value);
            $previous = $value;
        }

        try {
            return SynchronizationTargetSet::fromVersions($versions);
        } catch (InvalidArgumentException $failure) {
            throw $this->corrupt(
                'Redis synchronization targets violate Core invariants.',
                $failure,
            );
        }
    }

    private function parseCanonicalPositiveInt(
        string $value,
        string $field,
    ): int {
        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw $this->corrupt(sprintf(
                'Redis coordination [%s] must be a canonical positive decimal.',
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
                'Redis coordination [%s] is outside the supported integer range.',
                $field,
            ));
        }

        return $parsed;
    }

    private function parseCanonicalNonNegativeInt(
        string $value,
        string $field,
    ): int {
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            throw $this->corrupt(sprintf(
                'Redis coordination [%s] must be a canonical non-negative decimal.',
                $field,
            ));
        }

        $parsed = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        if ($parsed === false || (string) $parsed !== $value) {
            throw $this->corrupt(sprintf(
                'Redis coordination [%s] is outside the supported integer range.',
                $field,
            ));
        }

        return $parsed;
    }

    private function corrupt(
        string $message,
        ?Throwable $previous = null,
    ): CoordinationStateCorrupt {
        return new CoordinationStateCorrupt(
            $message,
            0,
            $previous,
        );
    }
}
