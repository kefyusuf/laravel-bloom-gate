<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractConflict;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;

final class MemoryGenerationContractStore implements GenerationContractStore
{
    /**
     * @var array<string, array<int, GenerationSemanticContract>>
     */
    private array $contracts = [];

    public function __construct(
        private BloomGenerationInspector $inspector,
    ) {}

    public function read(
        FilterName $name,
        FilterVersion $version,
    ): ?ManagedGenerationDescriptor {
        $layout = $this->requireLayout($name, $version);
        $semanticContract = $this->contracts[$name->value()][$version->value()] ?? null;

        if ($semanticContract === null) {
            return null;
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
        $actualLayout = $this->requireLayout($name, $version);

        if ($actualLayout->equals($expectedLayout) === false) {
            throw new BloomLayoutMismatch(
                'Managed generation semantic contract was bound against a different Bloom layout.',
            );
        }

        $nameKey = $name->value();
        $versionKey = $version->value();
        $existing = $this->contracts[$nameKey][$versionKey] ?? null;

        if ($existing !== null) {
            if ($existing->equals($semanticContract) === false) {
                throw new GenerationContractConflict(
                    'Managed generation semantic contract is already bound to different fingerprints.',
                );
            }

            return;
        }

        $this->contracts[$nameKey][$versionKey] = $semanticContract;
    }

    private function requireLayout(
        FilterName $name,
        FilterVersion $version,
    ): BloomLayout {
        $layout = $this->inspector->layout($name, $version);

        if ($layout === null) {
            unset($this->contracts[$name->value()][$version->value()]);

            throw new BloomFilterNotProvisioned(
                'Bloom generation is not provisioned for managed semantic binding.',
            );
        }

        return $layout;
    }
}
