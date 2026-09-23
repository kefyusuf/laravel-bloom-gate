<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;

final class Task10ControllableBloomDriver implements BulkBloomDriver
{
    public int $addManyCalls = 0;

    public int $mightContainCalls = 0;

    public int $destroyCalls = 0;

    /**
     * @var list<list<BitPositions>>
     */
    public array $batches = [];

    public function __construct(
        private MemoryBloomDriver $inner,
        private bool $failAddMany = false,
        private bool $failMightContain = false,
        private bool $noopAddMany = false,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function provision(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
    ): void {
        $this->inner->provision($name, $version, $layout);
    }

    public function add(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): void {
        $this->inner->add($name, $version, $positions);
    }

    public function addMany(
        FilterName $name,
        FilterVersion $version,
        array $items,
    ): void {
        $this->addManyCalls++;
        $this->batches[] = $items;

        if ($this->failAddMany) {
            throw new BloomDriverOperationFailed(
                'Task 10 simulated reconciliation failure.',
            );
        }

        if ($this->noopAddMany) {
            return;
        }

        $this->inner->addMany($name, $version, $items);
    }

    public function mightContain(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): bool {
        $this->mightContainCalls++;

        if ($this->failMightContain) {
            throw new BloomDriverOperationFailed(
                'Task 10 simulated verification read failure.',
            );
        }

        return $this->inner->mightContain($name, $version, $positions);
    }

    public function destroy(
        FilterName $name,
        FilterVersion $version,
    ): void {
        $this->destroyCalls++;
        $this->inner->destroy($name, $version);
    }
}
