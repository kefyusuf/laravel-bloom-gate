<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Contracts\Exception\WriterLeaseReleased;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use UnexpectedValueException;

final readonly class RedisWriterSynchronizationStore implements WriterSynchronizationStore
{
    public function __construct(
        private RedisStructuredCommandExecutor $executor,
        private RedisKeyspace $keyspace,
        private RedisCoordinationCodec $coordinationCodec,
    ) {}

    public function read(FilterName $name): ?SynchronizationState
    {
        $response = $this->evaluate(
            RedisWriterSynchronizationScripts::read(),
            [
                $this->keyspace->syncOwnerKey($name),
                $this->keyspace->syncKey($name),
            ],
            [],
        );

        if ($response === []) {
            throw $this->unexpectedReply('read', $response);
        }

        if ($response[0] === RedisWriterSynchronizationScripts::STATUS_NOT_COORDINATED) {
            if (count($response) !== 1) {
                throw $this->unexpectedReply('read', $response);
            }

            return null;
        }

        $this->throwIfFailure('read', $response);

        if (count($response) < 3 || (count($response) - 1) % 2 !== 0) {
            throw $this->unexpectedReply('read', $response);
        }

        return $this->coordinationCodec->decodeSynchronization(
            array_slice($response, 1),
        );
    }

    public function readLease(
        FilterName $name,
        WriterLeaseToken $token,
    ): ?WriterLease {
        $response = $this->evaluate(
            RedisWriterSynchronizationScripts::readLease(),
            [$this->keyspace->syncLeasesKey($name)],
            [$token->value()],
        );

        if (
            $response === [
                RedisWriterSynchronizationScripts::STATUS_UNKNOWN_LEASE,
            ]
        ) {
            return null;
        }

        return $this->leaseFromResponse(
            'readLease',
            $token,
            $response,
        );
    }

    public function acquire(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        return $this->leaseFromResponse(
            'acquire',
            $token,
            $this->evaluate(
                RedisWriterSynchronizationScripts::acquire(),
                [
                    $this->keyspace->syncOwnerKey($name),
                    $this->keyspace->syncKey($name),
                    $this->keyspace->syncLeasesKey($name),
                    $this->keyspace->syncCountsKey($name),
                ],
                [$token->value()],
            ),
        );
    }

    public function markPrepared(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        return $this->leaseFromResponse(
            'markPrepared',
            $token,
            $this->evaluate(
                RedisWriterSynchronizationScripts::markPrepared(),
                [
                    $this->keyspace->syncOwnerKey($name),
                    $this->keyspace->syncKey($name),
                    $this->keyspace->syncLeasesKey($name),
                    $this->keyspace->syncCountsKey($name),
                ],
                [$token->value()],
            ),
        );
    }

    public function release(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        return $this->leaseFromResponse(
            'release',
            $token,
            $this->evaluate(
                RedisWriterSynchronizationScripts::release(),
                [
                    $this->keyspace->syncLeasesKey($name),
                    $this->keyspace->syncCountsKey($name),
                ],
                [$token->value()],
            ),
        );
    }

    public function activeWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): int {
        $response = $this->evaluate(
            RedisWriterSynchronizationScripts::activeWriterCount(),
            [$this->keyspace->syncCountsKey($name)],
            [(string) $epoch->value()],
        );

        $this->throwIfFailure('activeWriterCount', $response);

        if (count($response) !== 2) {
            throw $this->unexpectedReply('activeWriterCount', $response);
        }

        return $this->coordinationCodec->decodeCount($response[1]);
    }

    /**
     * @param  list<string>  $response
     */
    private function leaseFromResponse(
        string $operation,
        WriterLeaseToken $token,
        array $response,
    ): WriterLease {
        $this->throwIfFailure($operation, $response);

        if (count($response) !== 2) {
            throw $this->unexpectedReply($operation, $response);
        }

        return $this->coordinationCodec->decodeLease(
            $token,
            $response[1],
        );
    }

    /**
     * @param  list<string>  $response
     */
    private function throwIfFailure(
        string $operation,
        array $response,
    ): void {
        if ($response === []) {
            throw $this->unexpectedReply($operation, $response);
        }

        if ($response[0] === RedisWriterSynchronizationScripts::STATUS_OK) {
            return;
        }

        if (count($response) !== 1) {
            throw $this->unexpectedReply($operation, $response);
        }

        match ($response[0]) {
            RedisWriterSynchronizationScripts::STATUS_FENCED,
            RedisWriterSynchronizationScripts::STATUS_NOT_COORDINATED => throw new CoordinationFenced(
                'Redis writer synchronization operation is fenced.',
            ),
            RedisWriterSynchronizationScripts::STATUS_CORRUPT => throw new CoordinationStateCorrupt(
                'Redis writer synchronization state is corrupt or incomplete.',
            ),
            RedisWriterSynchronizationScripts::STATUS_UNKNOWN_LEASE => throw new UnknownWriterLease(
                'Redis writer lease token is unknown for this filter.',
            ),
            RedisWriterSynchronizationScripts::STATUS_RELEASED_LEASE => throw new WriterLeaseReleased(
                'Redis writer lease token is terminal and cannot regain write authority.',
            ),
            default => throw $this->unexpectedReply($operation, $response),
        };
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function evaluate(
        string $script,
        array $keys,
        array $arguments,
    ): array {
        try {
            return $this->executor->evaluateStructured(
                $script,
                $keys,
                $arguments,
            );
        } catch (RedisCommandFailed $failure) {
            throw new CoordinationStoreOperationFailed(
                'Redis writer synchronization store operation failed.',
                0,
                $failure,
            );
        }
    }

    /**
     * @param  list<string>  $response
     */
    private function unexpectedReply(
        string $operation,
        array $response,
    ): UnexpectedValueException {
        return new UnexpectedValueException(sprintf(
            'Unexpected Redis writer synchronization reply for [%s]: [%s].',
            $operation,
            implode(', ', $response),
        ));
    }
}
