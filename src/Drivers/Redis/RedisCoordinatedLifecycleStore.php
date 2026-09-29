<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use UnexpectedValueException;

final readonly class RedisCoordinatedLifecycleStore implements CoordinatedLifecycleStore
{
    private const string STATUS_OK = '100';

    private const string STATUS_CONFLICT = '200';

    private const string STATUS_INVALID_REVISION = '202';

    private const string STATUS_FENCED = '203';

    private const string STATUS_CORRUPT = '204';

    public function __construct(
        private RedisStructuredCommandExecutor $executor,
        private RedisKeyspace $keyspace,
        private RedisControlStateCodec $controlCodec,
        private RedisCoordinationCodec $coordinationCodec,
    ) {}

    public function read(FilterName $name): CoordinatedLifecycleSnapshot
    {
        return $this->snapshotFromResponse(
            $name,
            'read',
            $this->evaluate(
                RedisCoordinationScripts::read(),
                [
                    $this->keyspace->stateKey($name),
                    $this->keyspace->syncOwnerKey($name),
                    $this->keyspace->syncKey($name),
                ],
                [],
            ),
        );
    }

    public function claimOwnership(
        FilterName $name,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        return $this->snapshotFromResponse(
            $name,
            'claimOwnership',
            $this->evaluate(
                RedisCoordinationScripts::claimOwnership(),
                [
                    $this->keyspace->stateKey($name),
                    $this->keyspace->syncOwnerKey($name),
                    $this->keyspace->syncKey($name),
                ],
                [
                    $expectedControlRevision === null
                        ? ''
                        : (string) $expectedControlRevision->value(),
                ],
            ),
        );
    }

    public function compareAndSwapControl(
        FilterName $name,
        FilterControlState $nextControl,
        ?FilterStateRevision $expectedControlRevision,
        SynchronizationRevision $expectedSyncRevision,
    ): CoordinatedLifecycleSnapshot {
        if ($name->equals($nextControl->filterName()) === false) {
            throw new InvalidArgumentException(
                'Control state target filter name must match the snapshot filter name.',
            );
        }

        return $this->snapshotFromResponse(
            $name,
            'compareAndSwapControl',
            $this->evaluate(
                RedisCoordinationScripts::compareAndSwapControl(),
                [
                    $this->keyspace->stateKey($name),
                    $this->keyspace->stateStagingKey($name),
                    $this->keyspace->syncOwnerKey($name),
                    $this->keyspace->syncKey($name),
                ],
                [
                    $expectedControlRevision === null
                        ? ''
                        : (string) $expectedControlRevision->value(),
                    (string) $expectedSyncRevision->value(),
                    ...$this->controlCodec->encode($nextControl),
                ],
            ),
        );
    }

    public function compareAndSwapSynchronization(
        FilterName $name,
        SynchronizationState $nextSynchronization,
        ?SynchronizationRevision $expectedSyncRevision,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        return $this->snapshotFromResponse(
            $name,
            'compareAndSwapSynchronization',
            $this->evaluate(
                RedisCoordinationScripts::compareAndSwapSynchronization(),
                [
                    $this->keyspace->stateKey($name),
                    $this->keyspace->syncOwnerKey($name),
                    $this->keyspace->syncKey($name),
                    $this->keyspace->syncStagingKey($name),
                ],
                [
                    $expectedControlRevision === null
                        ? ''
                        : (string) $expectedControlRevision->value(),
                    $expectedSyncRevision === null
                        ? ''
                        : (string) $expectedSyncRevision->value(),
                    ...$this->coordinationCodec->encodeSynchronization(
                        $nextSynchronization,
                    ),
                ],
            ),
        );
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
                'Redis coordinated lifecycle store operation failed.',
                0,
                $failure,
            );
        }
    }

    /**
     * @param  list<string>  $response
     */
    private function snapshotFromResponse(
        FilterName $name,
        string $operation,
        array $response,
    ): CoordinatedLifecycleSnapshot {
        if ($response === []) {
            throw $this->unexpectedReply($operation, $response);
        }

        $status = $response[0];

        if ($status !== self::STATUS_OK) {
            if (count($response) !== 1) {
                throw $this->unexpectedReply($operation, $response);
            }

            return match ($status) {
                self::STATUS_CONFLICT => throw new CoordinationWriteConflict(
                    'Redis coordinated lifecycle revision relation does not match.',
                ),
                self::STATUS_INVALID_REVISION => throw new InvalidArgumentException(
                    'Redis coordinated lifecycle revision must advance exactly once.',
                ),
                self::STATUS_FENCED => throw new CoordinationFenced(
                    'Redis coordinated lifecycle mutation is fenced.',
                ),
                self::STATUS_CORRUPT => throw new CoordinationStateCorrupt(
                    'Redis coordinated lifecycle state is corrupt or incomplete.',
                ),
                default => throw $this->unexpectedReply($operation, $response),
            };
        }

        if (count($response) < 4) {
            throw $this->unexpectedReply($operation, $response);
        }

        $ownerToken = $response[1];

        if ($ownerToken !== '0' && $ownerToken !== '1') {
            throw $this->unexpectedReply($operation, $response);
        }

        $controlCount = $this->responseCount(
            $response[2],
            $operation,
            $response,
        );
        $controlStart = 3;
        $syncCountIndex = $controlStart + $controlCount;

        if (! array_key_exists($syncCountIndex, $response)) {
            throw $this->unexpectedReply($operation, $response);
        }

        $controlPayload = array_slice(
            $response,
            $controlStart,
            $controlCount,
        );
        $syncCount = $this->responseCount(
            $response[$syncCountIndex],
            $operation,
            $response,
        );
        $syncStart = $syncCountIndex + 1;
        $syncPayload = array_slice(
            $response,
            $syncStart,
            $syncCount,
        );

        if ($syncStart + $syncCount !== count($response)) {
            throw $this->unexpectedReply($operation, $response);
        }

        try {
            $control = $controlPayload === []
                ? null
                : $this->controlCodec->decode($name, $controlPayload);
        } catch (FilterControlStateCorrupt $failure) {
            throw new CoordinationStateCorrupt(
                'Redis coordinated control snapshot is corrupt.',
                0,
                $failure,
            );
        }

        $synchronization = $syncPayload === []
            ? null
            : $this->coordinationCodec->decodeSynchronization(
                $syncPayload,
            );

        try {
            return new CoordinatedLifecycleSnapshot(
                ownershipClaimed: $ownerToken === '1',
                control: $control,
                synchronization: $synchronization,
            );
        } catch (InvalidArgumentException $failure) {
            throw new CoordinationStateCorrupt(
                'Redis coordinated lifecycle snapshot is contradictory.',
                0,
                $failure,
            );
        }
    }

    /**
     * @param  list<string>  $response
     */
    private function responseCount(
        string $token,
        string $operation,
        array $response,
    ): int {
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $token) !== 1) {
            throw $this->unexpectedReply($operation, $response);
        }

        $count = filter_var(
            $token,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        if ($count === false || (string) $count !== $token) {
            throw $this->unexpectedReply($operation, $response);
        }

        return $count;
    }

    /**
     * @param  list<string>  $response
     */
    private function unexpectedReply(
        string $operation,
        array $response,
    ): UnexpectedValueException {
        return new UnexpectedValueException(sprintf(
            'Unexpected Redis coordinated lifecycle reply for [%s]: [%s].',
            $operation,
            implode(', ', $response),
        ));
    }
}
