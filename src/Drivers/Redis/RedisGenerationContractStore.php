<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractConflict;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use UnexpectedValueException;

final readonly class RedisGenerationContractStore implements BloomGenerationInspector, GenerationContractStore
{
    public function __construct(
        private RedisStructuredCommandExecutor $executor,
        private RedisKeyspace $keyspace,
    ) {}

    public function layout(
        FilterName $name,
        FilterVersion $version,
    ): ?BloomLayout {
        $response = $this->readResponse($name, $version);

        if ($response[0] === RedisGenerationContractScripts::STATUS_NOT_PROVISIONED) {
            return null;
        }

        return $this->layoutFromReadResponse($response);
    }

    public function read(
        FilterName $name,
        FilterVersion $version,
    ): ?ManagedGenerationDescriptor {
        $response = $this->readResponse($name, $version);

        if ($response[0] === RedisGenerationContractScripts::STATUS_NOT_PROVISIONED) {
            throw new BloomFilterNotProvisioned(
                'Redis Bloom generation is not provisioned for managed semantic metadata.',
            );
        }

        $layout = $this->layoutFromReadResponse($response);

        if ($response[5] === '' && $response[6] === '' && $response[7] === '') {
            return null;
        }

        try {
            $semanticContract = new GenerationSemanticContract(
                normalizationFingerprint: NormalizationFingerprint::fromString($response[5]),
                authoritativeSetFingerprint: AuthoritativeSetFingerprint::fromString($response[6]),
                consistencyFingerprint: ConsistencyFingerprint::fromString($response[7]),
            );
        } catch (InvalidArgumentException $failure) {
            throw new BloomStorageCorrupt(
                'Redis managed generation semantic metadata is corrupt.',
                0,
                $failure,
            );
        }

        return new ManagedGenerationDescriptor(
            layout: $layout,
            semanticContract: $semanticContract,
        );
    }

    public function bind(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $expectedLayout,
        GenerationSemanticContract $semanticContract,
    ): void {
        $response = $this->evaluateStructured(
            RedisGenerationContractScripts::bind(),
            $name,
            $version,
            [
                RedisBloomScripts::STORAGE_FORMAT,
                (string) $expectedLayout->bitCount(),
                (string) $expectedLayout->hashCount(),
                $expectedLayout->probeAlgorithm()->value,
                $semanticContract->normalizationFingerprint()->value(),
                $semanticContract->authoritativeSetFingerprint()->value(),
                $semanticContract->consistencyFingerprint()->value(),
            ],
        );

        if (count($response) !== 1) {
            throw $this->unexpectedReply('bind', $response);
        }

        match ($response[0]) {
            RedisGenerationContractScripts::STATUS_OK => null,
            RedisGenerationContractScripts::STATUS_NOT_PROVISIONED => throw new BloomFilterNotProvisioned(
                'Redis Bloom generation is not provisioned for managed semantic binding.',
            ),
            RedisGenerationContractScripts::STATUS_STORAGE_CORRUPT => throw new BloomStorageCorrupt(
                'Redis managed generation storage is corrupt.',
            ),
            RedisGenerationContractScripts::STATUS_LAYOUT_MISMATCH => throw new BloomLayoutMismatch(
                'Redis managed generation layout does not match the provisioned layout.',
            ),
            RedisGenerationContractScripts::STATUS_CONTRACT_CONFLICT => throw new GenerationContractConflict(
                'Redis managed generation semantic contract is already bound to different fingerprints.',
            ),
            default => throw $this->unexpectedReply('bind', $response),
        };
    }

    /**
     * @return list<string>
     */
    private function readResponse(
        FilterName $name,
        FilterVersion $version,
    ): array {
        $response = $this->evaluateStructured(
            RedisGenerationContractScripts::read(),
            $name,
            $version,
            [],
        );

        if ($response === []) {
            throw $this->unexpectedReply('read', $response);
        }

        return match ($response[0]) {
            RedisGenerationContractScripts::STATUS_OK => count($response) === 8
                ? $response
                : throw $this->unexpectedReply('read', $response),
            RedisGenerationContractScripts::STATUS_NOT_PROVISIONED => count($response) === 1
                ? $response
                : throw $this->unexpectedReply('read', $response),
            RedisGenerationContractScripts::STATUS_STORAGE_CORRUPT => throw new BloomStorageCorrupt(
                'Redis managed generation storage is corrupt.',
            ),
            default => throw $this->unexpectedReply('read', $response),
        };
    }

    /**
     * @param  list<string>  $response
     */
    private function layoutFromReadResponse(array $response): BloomLayout
    {
        if (count($response) !== 8) {
            throw $this->unexpectedReply('read', $response);
        }

        if ($response[1] !== RedisBloomScripts::STORAGE_FORMAT) {
            throw new BloomStorageCorrupt(
                'Redis managed generation storage format is corrupt.',
            );
        }

        $probeAlgorithm = ProbeAlgorithm::tryFrom($response[4]);

        if ($probeAlgorithm === null) {
            throw new BloomLayoutMismatch(
                'Redis managed generation uses an unsupported probe algorithm.',
            );
        }

        try {
            return BloomLayout::create(
                bitCount: (int) $response[2],
                hashCount: (int) $response[3],
                probeAlgorithm: $probeAlgorithm,
            );
        } catch (InvalidArgumentException $failure) {
            throw new BloomStorageCorrupt(
                'Redis managed generation layout metadata is corrupt.',
                0,
                $failure,
            );
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function evaluateStructured(
        string $script,
        FilterName $name,
        FilterVersion $version,
        array $arguments,
    ): array {
        try {
            return $this->executor->evaluateStructured(
                $script,
                [
                    $this->keyspace->metaKey($name, $version),
                    $this->keyspace->bitmapKey($name, $version),
                ],
                $arguments,
            );
        } catch (RedisCommandFailed $failure) {
            throw new GenerationContractStoreOperationFailed(
                'Redis managed generation contract store operation failed.',
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
            'Unexpected Redis managed generation reply for [%s]: [%s].',
            $operation,
            implode(', ', $response),
        ));
    }
}
