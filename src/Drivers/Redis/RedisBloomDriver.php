<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutConflict;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use UnexpectedValueException;

final readonly class RedisBloomDriver implements BulkBloomDriver
{
    public function __construct(
        private RedisCommandExecutor $executor,
        private RedisKeyspace $keyspace,
    ) {}

    public function provision(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
    ): void {
        $status = $this->evaluate(
            RedisBloomScripts::provision(),
            $name,
            $version,
            $this->layoutArguments($layout),
        );

        match ($status) {
            RedisBloomScripts::STATUS_OK => null,
            RedisBloomScripts::STATUS_LAYOUT_CONFLICT => throw new BloomLayoutConflict(
                'Redis Bloom generation is already provisioned with a different layout.',
            ),
            RedisBloomScripts::STATUS_STORAGE_CORRUPT => throw new BloomStorageCorrupt(
                'Redis Bloom generation storage is corrupt.',
            ),
            default => throw $this->unexpectedStatus('provision', $status),
        };
    }

    public function add(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): void {
        $arguments = [
            ...$this->layoutArguments($positions->layout()),
            ...array_map(static fn (int $position): string => (string) $position, $positions->values()),
        ];

        $status = $this->evaluate(
            RedisBloomScripts::add(),
            $name,
            $version,
            $arguments,
        );

        match ($status) {
            RedisBloomScripts::STATUS_OK => null,
            RedisBloomScripts::STATUS_NOT_PROVISIONED => throw new BloomFilterNotProvisioned(
                'Redis Bloom generation is not provisioned.',
            ),
            RedisBloomScripts::STATUS_LAYOUT_MISMATCH => throw new BloomLayoutMismatch(
                'Redis Bloom positions do not match the provisioned layout.',
            ),
            RedisBloomScripts::STATUS_STORAGE_CORRUPT => throw new BloomStorageCorrupt(
                'Redis Bloom generation storage is corrupt.',
            ),
            default => throw $this->unexpectedStatus('add', $status),
        };
    }

    /**
     * @param  list<BitPositions>  $items
     */
    public function addMany(
        FilterName $name,
        FilterVersion $version,
        array $items,
    ): void {
        if ($items === []) {
            return;
        }

        $layout = $items[0]->layout();
        $positionArguments = [];

        foreach ($items as $positions) {
            if ($layout->equals($positions->layout()) === false) {
                throw new BloomLayoutMismatch(
                    'Redis bulk Bloom positions do not share one equivalent layout.',
                );
            }

            foreach ($positions->values() as $position) {
                $positionArguments[] = (string) $position;
            }
        }

        $status = $this->evaluate(
            RedisBloomScripts::addMany(),
            $name,
            $version,
            [
                ...$this->layoutArguments($layout),
                ...$positionArguments,
            ],
        );

        match ($status) {
            RedisBloomScripts::STATUS_OK => null,
            RedisBloomScripts::STATUS_NOT_PROVISIONED => throw new BloomFilterNotProvisioned(
                'Redis Bloom generation is not provisioned.',
            ),
            RedisBloomScripts::STATUS_LAYOUT_MISMATCH => throw new BloomLayoutMismatch(
                'Redis bulk Bloom positions do not match the provisioned layout.',
            ),
            RedisBloomScripts::STATUS_STORAGE_CORRUPT => throw new BloomStorageCorrupt(
                'Redis Bloom generation storage is corrupt.',
            ),
            RedisBloomScripts::STATUS_INVALID_BATCH => throw $this->unexpectedStatus(
                'addMany',
                $status,
            ),
            default => throw $this->unexpectedStatus('addMany', $status),
        };
    }

    public function mightContain(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): bool {
        $arguments = [
            ...$this->layoutArguments($positions->layout()),
            ...array_map(static fn (int $position): string => (string) $position, $positions->values()),
        ];

        $status = $this->evaluate(
            RedisBloomScripts::mightContain(),
            $name,
            $version,
            $arguments,
        );

        return match ($status) {
            RedisBloomScripts::STATUS_MEMBERSHIP_ABSENT => false,
            RedisBloomScripts::STATUS_MEMBERSHIP_MAYBE_PRESENT => true,
            RedisBloomScripts::STATUS_NOT_PROVISIONED => throw new BloomFilterNotProvisioned(
                'Redis Bloom generation is not provisioned.',
            ),
            RedisBloomScripts::STATUS_LAYOUT_MISMATCH => throw new BloomLayoutMismatch(
                'Redis Bloom positions do not match the provisioned layout.',
            ),
            RedisBloomScripts::STATUS_STORAGE_CORRUPT => throw new BloomStorageCorrupt(
                'Redis Bloom generation storage is corrupt.',
            ),
            default => throw $this->unexpectedStatus('mightContain', $status),
        };
    }

    public function destroy(
        FilterName $name,
        FilterVersion $version,
    ): void {
        $status = $this->evaluate(
            RedisBloomScripts::destroy(),
            $name,
            $version,
            [],
        );

        if ($status !== RedisBloomScripts::STATUS_OK) {
            throw $this->unexpectedStatus('destroy', $status);
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function evaluate(
        string $script,
        FilterName $name,
        FilterVersion $version,
        array $arguments,
    ): int {
        try {
            return $this->executor->evaluate(
                $script,
                [
                    $this->keyspace->metaKey($name, $version),
                    $this->keyspace->bitmapKey($name, $version),
                ],
                $arguments,
            );
        } catch (RedisCommandFailed $failure) {
            throw new BloomDriverOperationFailed(
                'Redis Bloom driver operation failed.',
                0,
                $failure,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function layoutArguments(BloomLayout $layout): array
    {
        return [
            RedisBloomScripts::STORAGE_FORMAT,
            (string) $layout->bitCount(),
            (string) $layout->hashCount(),
            $layout->probeAlgorithm()->value,
        ];
    }

    private function unexpectedStatus(string $operation, int $status): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf(
            'Unexpected Redis Bloom script status [%d] for [%s].',
            $status,
            $operation,
        ));
    }
}
