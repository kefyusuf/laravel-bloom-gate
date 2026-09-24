<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use UnexpectedValueException;

final readonly class RedisAuthorizedProbe implements AuthorizedProbe
{
    public const string TRUSTED_NEGATIVE_PROFILE = 'standalone-primary-durable-v1';

    public function __construct(
        private RedisStructuredCommandExecutor $executor,
        private RedisKeyspace $keyspace,
        private ?string $trustedNegativeProfile,
    ) {}

    public function probe(
        QuerySafetyDescriptor $descriptor,
        BitPositions $positions,
    ): AuthorizedProbeResult {
        if ($descriptor->layout()->equals($positions->layout()) === false) {
            throw new BloomLayoutMismatch(
                'Authorized Redis probe positions do not match the pinned generation layout.',
            );
        }

        if ($this->trustedNegativeProfile !== self::TRUSTED_NEGATIVE_PROFILE) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::backendProfileUnasserted(),
            );
        }

        $contract = $descriptor->semanticContract();
        $layout = $descriptor->layout();

        try {
            $response = $this->executor->evaluateStructured(
                RedisQuerySafetyScripts::authorizedProbe(),
                [
                    $this->keyspace->stateKey($descriptor->filterName()),
                    $this->keyspace->metaKey(
                        $descriptor->filterName(),
                        $descriptor->activeVersion(),
                    ),
                    $this->keyspace->bitmapKey(
                        $descriptor->filterName(),
                        $descriptor->activeVersion(),
                    ),
                ],
                [
                    (string) $descriptor->revision()->value(),
                    (string) $descriptor->activeVersion()->value(),
                    RedisBloomScripts::STORAGE_FORMAT,
                    (string) $layout->bitCount(),
                    (string) $layout->hashCount(),
                    $layout->probeAlgorithm()->value,
                    $contract->normalizationFingerprint()->value(),
                    $contract->authoritativeSetFingerprint()->value(),
                    $contract->consistencyFingerprint()->value(),
                    ...array_map(
                        static fn (int $position): string => (string) $position,
                        $positions->values(),
                    ),
                ],
            );
        } catch (RedisCommandFailed) {
            return AuthorizedProbeResult::bypassed(
                BypassReason::backendUnavailable(),
            );
        }

        if ($response === [RedisQuerySafetyScripts::RESULT_ABSENT]) {
            return AuthorizedProbeResult::definitelyAbsent();
        }

        if ($response === [RedisQuerySafetyScripts::RESULT_MAYBE]) {
            return AuthorizedProbeResult::maybePresent();
        }

        if (
            count($response) === 2
            && $response[0] === RedisQuerySafetyScripts::RESULT_BYPASS
        ) {
            return AuthorizedProbeResult::bypassed(
                $this->bypassReason($response[1]),
            );
        }

        throw new UnexpectedValueException(sprintf(
            'Unexpected Redis authorized probe reply: [%s].',
            implode(', ', $response),
        ));
    }

    private function bypassReason(string $code): BypassReason
    {
        return match ($code) {
            'control_state_changed' => BypassReason::controlStateChanged(),
            'operation_failed' => BypassReason::operationFailed(),
            'generation_storage_unavailable' => BypassReason::generationStorageUnavailable(),
            'generation_storage_corrupt' => BypassReason::generationStorageCorrupt(),
            'generation_contract_unbound' => BypassReason::generationContractUnbound(),
            'normalization_mismatch' => BypassReason::normalizationMismatch(),
            'authoritative_set_mismatch' => BypassReason::authoritativeSetMismatch(),
            'consistency_mismatch' => BypassReason::consistencyMismatch(),
            default => throw new UnexpectedValueException(sprintf(
                'Unexpected Redis authorized probe bypass reason [%s].',
                $code,
            )),
        };
    }
}
