<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use UnexpectedValueException;

final readonly class RedisFilterControlStore implements ActiveGenerationSnapshotReader, FilterControlStore
{
    private const string STATUS_OK = '100';

    private const string STATUS_REVISION_CONFLICT = '200';

    private const string STATUS_STORAGE_CORRUPT = '201';

    private const string STATUS_INVALID_REVISION = '202';

    public function __construct(
        private RedisStructuredCommandExecutor $executor,
        private RedisKeyspace $keyspace,
        private RedisControlStateCodec $codec,
    ) {}

    public function readActive(FilterName $name): ?ActiveGenerationSnapshot
    {
        $response = $this->evaluateStructured(
            RedisQuerySafetyScripts::readActive(),
            [$this->keyspace->stateKey($name)],
            [],
        );

        if ($response === []) {
            throw $this->unexpectedReply('readActive', $response);
        }

        if ($response[0] === RedisQuerySafetyScripts::STATUS_NO_ACTIVE) {
            if (count($response) !== 1) {
                throw $this->unexpectedReply('readActive', $response);
            }

            return null;
        }

        if ($response[0] === RedisQuerySafetyScripts::STATUS_CORRUPT) {
            if (count($response) !== 1) {
                throw $this->unexpectedReply('readActive', $response);
            }

            throw new FilterControlStateCorrupt(
                'Redis active-generation control snapshot is corrupt.',
            );
        }

        if (
            $response[0] !== RedisQuerySafetyScripts::STATUS_ACTIVE
            || count($response) !== 5
        ) {
            throw $this->unexpectedReply('readActive', $response);
        }

        $revision = $this->parseCanonicalPositiveInt(
            $response[1],
            'revision',
        );
        $activeVersion = $this->parseCanonicalPositiveInt(
            $response[2],
            'active_version',
        );

        return new ActiveGenerationSnapshot(
            filterName: $name,
            revision: FilterStateRevision::fromInt($revision),
            activeVersion: FilterVersion::fromInt($activeVersion),
            lifecycle: $this->decodeLifecycle($response[3]),
            health: $this->decodeHealth($response[4]),
        );
    }

    public function read(FilterName $name): ?FilterControlState
    {
        $response = $this->evaluateStructured(
            RedisControlScripts::read(),
            [$this->keyspace->stateKey($name)],
            [],
        );

        if ($response === []) {
            throw $this->unexpectedReply('read', $response);
        }

        $status = $response[0];

        if ($status === self::STATUS_STORAGE_CORRUPT) {
            if (count($response) !== 1) {
                throw $this->unexpectedReply('read', $response);
            }

            throw new FilterControlStateCorrupt(
                'Redis lifecycle control state storage is corrupt.',
            );
        }

        if ($status !== self::STATUS_OK) {
            throw $this->unexpectedReply('read', $response);
        }

        $payload = array_slice($response, 1);

        if ($payload === []) {
            return null;
        }

        return $this->codec->decode($name, $payload);
    }

    public function compareAndSwap(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void {
        if ($name->equals($next->filterName()) === false) {
            throw new InvalidArgumentException(
                'Control state target filter name must match the snapshot filter name.',
            );
        }

        $response = $this->evaluateStructured(
            RedisControlScripts::compareAndSwap(),
            [
                $this->keyspace->stateKey($name),
                $this->keyspace->stateStagingKey($name),
            ],
            [
                $expectedRevision === null
                    ? ''
                    : (string) $expectedRevision->value(),
                ...$this->codec->encode($next),
            ],
        );

        if (count($response) !== 1) {
            throw $this->unexpectedReply('compareAndSwap', $response);
        }

        match ($response[0]) {
            self::STATUS_OK => null,
            self::STATUS_REVISION_CONFLICT => throw new FilterControlWriteConflict(
                'Redis lifecycle control state revision does not match the expected revision.',
            ),
            self::STATUS_STORAGE_CORRUPT => throw new FilterControlStateCorrupt(
                'Redis lifecycle control state storage is corrupt.',
            ),
            self::STATUS_INVALID_REVISION => throw new InvalidArgumentException(
                'Redis lifecycle control state revision must advance exactly once.',
            ),
            default => throw $this->unexpectedReply('compareAndSwap', $response),
        };
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function evaluateStructured(
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
            throw new FilterControlStoreOperationFailed(
                'Redis lifecycle control store operation failed.',
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
            'Unexpected Redis lifecycle control reply for [%s]: [%s].',
            $operation,
            implode(', ', $response),
        ));
    }
}
