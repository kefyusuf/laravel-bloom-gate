<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use PHPUnit\Framework\TestCase;

abstract class BulkBloomDriverContractTestCase extends TestCase
{
    abstract protected function makeBulkDriver(): BulkBloomDriver;

    public function test_empty_batch_is_a_successful_no_op_without_storage(): void
    {
        $this->makeBulkDriver()->addMany(
            $this->filterName(),
            $this->version(),
            [],
        );

        self::assertTrue(true);
    }

    public function test_non_empty_batch_sets_every_items_positions(): void
    {
        $driver = $this->makeBulkDriver();
        $layout = $this->layout();
        $first = $this->positions($layout, [1, 4, 7]);
        $second = $this->positions($layout, [2, 5, 8]);

        $driver->provision($this->filterName(), $this->version(), $layout);
        $driver->addMany(
            $this->filterName(),
            $this->version(),
            [$first, $second],
        );

        self::assertTrue($driver->mightContain(
            $this->filterName(),
            $this->version(),
            $first,
        ));
        self::assertTrue($driver->mightContain(
            $this->filterName(),
            $this->version(),
            $second,
        ));
    }

    public function test_equivalent_layout_instances_are_accepted_in_one_batch(): void
    {
        $driver = $this->makeBulkDriver();
        $layout = $this->layout();
        $equivalent = BloomLayout::create(
            $layout->bitCount(),
            $layout->hashCount(),
            $layout->probeAlgorithm(),
        );
        $first = $this->positions($layout, [1, 4, 7]);
        $second = $this->positions($equivalent, [2, 5, 8]);

        $driver->provision($this->filterName(), $this->version(), $layout);
        $driver->addMany(
            $this->filterName(),
            $this->version(),
            [$first, $second],
        );

        self::assertTrue($driver->mightContain(
            $this->filterName(),
            $this->version(),
            $first,
        ));
        self::assertTrue($driver->mightContain(
            $this->filterName(),
            $this->version(),
            $second,
        ));
    }

    public function test_mixed_layout_batch_fails_before_any_item_is_mutated(): void
    {
        $driver = $this->makeBulkDriver();
        $layout = $this->layout();
        $first = $this->positions($layout, [1, 4, 7]);
        $different = $this->positions(
            BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1),
            [2, 5, 8],
        );

        $driver->provision($this->filterName(), $this->version(), $layout);

        try {
            $driver->addMany(
                $this->filterName(),
                $this->version(),
                [$first, $different],
            );

            self::fail('Expected a mixed-layout bulk write to fail.');
        } catch (BloomLayoutMismatch) {
            self::assertFalse($driver->mightContain(
                $this->filterName(),
                $this->version(),
                $first,
            ));
        }
    }

    public function test_non_empty_batch_requires_provisioned_storage(): void
    {
        $this->expectException(BloomFilterNotProvisioned::class);

        $this->makeBulkDriver()->addMany(
            $this->filterName(),
            $this->version(),
            [$this->positions($this->layout(), [1, 4, 7])],
        );
    }

    public function test_repeated_batches_and_duplicate_items_are_retry_safe(): void
    {
        $driver = $this->makeBulkDriver();
        $layout = $this->layout();
        $item = $this->positions($layout, [1, 4, 7]);

        $driver->provision($this->filterName(), $this->version(), $layout);
        $driver->addMany(
            $this->filterName(),
            $this->version(),
            [$item, $item],
        );
        $driver->addMany(
            $this->filterName(),
            $this->version(),
            [$item, $item],
        );

        self::assertTrue($driver->mightContain(
            $this->filterName(),
            $this->version(),
            $item,
        ));
    }

    protected function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    protected function version(): FilterVersion
    {
        return FilterVersion::fromInt(1);
    }

    protected function layout(): BloomLayout
    {
        return BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
    }

    /**
     * @param  list<int>  $positions
     */
    protected function positions(
        BloomLayout $layout,
        array $positions,
    ): BitPositions {
        return BitPositions::forLayout($layout, $positions);
    }
}
