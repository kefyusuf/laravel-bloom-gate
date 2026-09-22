<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutConflict;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;

final class MemoryBloomDriver implements BloomDriver, BloomGenerationInspector
{
    /**
     * @var array<string, array<int, array{layout: BloomLayout, bits: array<int, true>}>>
     */
    private array $filters = [];

    public function provision(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
    ): void {
        $nameKey = $name->value();
        $versionKey = $version->value();
        $existing = $this->filters[$nameKey][$versionKey] ?? null;

        if ($existing !== null) {
            if ($existing['layout']->equals($layout) === false) {
                throw new BloomLayoutConflict(
                    'Bloom filter storage is already provisioned with a different layout.',
                );
            }

            return;
        }

        $this->filters[$nameKey][$versionKey] = [
            'layout' => $layout,
            'bits' => [],
        ];
    }

    public function add(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): void {
        $entry = $this->entry($name, $version);
        $this->assertLayoutMatches($entry['layout'], $positions->layout());

        foreach ($positions->values() as $position) {
            $entry['bits'][$position] = true;
        }

        $this->filters[$name->value()][$version->value()] = $entry;
    }

    public function mightContain(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): bool {
        $entry = $this->entry($name, $version);
        $this->assertLayoutMatches($entry['layout'], $positions->layout());

        foreach ($positions->values() as $position) {
            if (isset($entry['bits'][$position]) === false) {
                return false;
            }
        }

        return true;
    }

    public function destroy(
        FilterName $name,
        FilterVersion $version,
    ): void {
        unset($this->filters[$name->value()][$version->value()]);
    }

    public function layout(
        FilterName $name,
        FilterVersion $version,
    ): ?BloomLayout {
        return $this->filters[$name->value()][$version->value()]['layout'] ?? null;
    }

    /**
     * @return array{layout: BloomLayout, bits: array<int, true>}
     */
    private function entry(
        FilterName $name,
        FilterVersion $version,
    ): array {
        $entry = $this->filters[$name->value()][$version->value()] ?? null;

        if ($entry === null) {
            throw new BloomFilterNotProvisioned(
                'Bloom filter storage has not been provisioned for this filter version.',
            );
        }

        return $entry;
    }

    private function assertLayoutMatches(
        BloomLayout $provisioned,
        BloomLayout $requested,
    ): void {
        if ($provisioned->equals($requested) === false) {
            throw new BloomLayoutMismatch(
                'Bloom bit positions were generated for a different layout.',
            );
        }
    }
}
