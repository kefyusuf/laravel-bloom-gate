<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutConflict;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use PHPUnit\Framework\TestCase;

abstract class BloomDriverContractTestCase extends TestCase
{
    abstract protected function makeDriver(): BloomDriver;

    public function test_provision_creates_empty_storage(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();

        $driver->provision($this->filterName(), $this->version(), $layout);

        self::assertFalse($driver->mightContain(
            $this->filterName(),
            $this->version(),
            $this->positions($layout, [1, 4, 7]),
        ));
    }

    public function test_add_sets_positions_and_might_contain_requires_all_positions(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();
        $driver->provision($this->filterName(), $this->version(), $layout);

        $driver->add(
            $this->filterName(),
            $this->version(),
            $this->positions($layout, [1, 4, 7]),
        );

        self::assertTrue($driver->mightContain(
            $this->filterName(),
            $this->version(),
            $this->positions($layout, [1, 4, 7]),
        ));

        self::assertFalse($driver->mightContain(
            $this->filterName(),
            $this->version(),
            $this->positions($layout, [1, 4, 8]),
        ));
    }

    public function test_add_is_monotonic_and_retry_safe(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();
        $first = $this->positions($layout, [1, 4, 7]);
        $second = $this->positions($layout, [2, 5, 8]);

        $driver->provision($this->filterName(), $this->version(), $layout);
        $driver->add($this->filterName(), $this->version(), $first);
        $driver->add($this->filterName(), $this->version(), $first);
        $driver->add($this->filterName(), $this->version(), $second);

        self::assertTrue($driver->mightContain($this->filterName(), $this->version(), $first));
        self::assertTrue($driver->mightContain($this->filterName(), $this->version(), $second));
    }

    public function test_same_layout_provision_is_retry_safe_and_does_not_clear_bits(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();
        $positions = $this->positions($layout, [1, 4, 7]);

        $driver->provision($this->filterName(), $this->version(), $layout);
        $driver->add($this->filterName(), $this->version(), $positions);

        $equivalentLayout = BloomLayout::create(
            $layout->bitCount(),
            $layout->hashCount(),
            $layout->probeAlgorithm(),
        );

        $driver->provision($this->filterName(), $this->version(), $equivalentLayout);

        self::assertTrue($driver->mightContain($this->filterName(), $this->version(), $positions));
    }

    public function test_conflicting_layout_provision_fails_without_mutating_existing_storage(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();
        $positions = $this->positions($layout, [1, 4, 7]);

        $driver->provision($this->filterName(), $this->version(), $layout);
        $driver->add($this->filterName(), $this->version(), $positions);

        try {
            $driver->provision(
                $this->filterName(),
                $this->version(),
                BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1),
            );

            self::fail('Expected a conflicting provision to throw.');
        } catch (BloomLayoutConflict) {
            self::assertTrue($driver->mightContain($this->filterName(), $this->version(), $positions));
        }
    }

    public function test_add_rejects_positions_from_a_different_layout(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();
        $driver->provision($this->filterName(), $this->version(), $layout);

        $this->expectException(BloomLayoutMismatch::class);

        $driver->add(
            $this->filterName(),
            $this->version(),
            $this->positions(
                BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1),
                [1, 4, 7],
            ),
        );
    }

    public function test_might_contain_rejects_positions_from_a_different_layout(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();
        $driver->provision($this->filterName(), $this->version(), $layout);

        $this->expectException(BloomLayoutMismatch::class);

        $driver->mightContain(
            $this->filterName(),
            $this->version(),
            $this->positions(
                BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1),
                [1, 4, 7],
            ),
        );
    }

    public function test_missing_storage_is_a_typed_failure_for_add(): void
    {
        $layout = $this->layout();

        $this->expectException(BloomFilterNotProvisioned::class);

        $this->makeDriver()->add(
            $this->filterName(),
            $this->version(),
            $this->positions($layout, [1, 4, 7]),
        );
    }

    public function test_missing_storage_is_a_typed_failure_for_might_contain(): void
    {
        $layout = $this->layout();

        $this->expectException(BloomFilterNotProvisioned::class);

        $this->makeDriver()->mightContain(
            $this->filterName(),
            $this->version(),
            $this->positions($layout, [1, 4, 7]),
        );
    }

    public function test_destroy_removes_only_the_requested_generation_and_is_retry_safe(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();
        $name = $this->filterName();
        $version1 = FilterVersion::fromInt(1);
        $version2 = FilterVersion::fromInt(2);
        $positions = $this->positions($layout, [1, 4, 7]);

        $driver->provision($name, $version1, $layout);
        $driver->provision($name, $version2, $layout);
        $driver->add($name, $version2, $positions);

        $driver->destroy($name, $version1);
        $driver->destroy($name, $version1);

        self::assertTrue($driver->mightContain($name, $version2, $positions));

        $this->expectException(BloomFilterNotProvisioned::class);

        $driver->mightContain($name, $version1, $positions);
    }

    public function test_duplicate_positions_are_preserved_by_contract_semantics(): void
    {
        $driver = $this->makeDriver();
        $layout = BloomLayout::create(16, 4, ProbeAlgorithm::Sha256DoubleHashV1);
        $positions = $this->positions($layout, [3, 7, 3, 15]);

        $driver->provision($this->filterName(), $this->version(), $layout);
        $driver->add($this->filterName(), $this->version(), $positions);

        self::assertTrue($driver->mightContain($this->filterName(), $this->version(), $positions));
    }

    public function test_filter_name_and_version_form_independent_storage_identity(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();
        $nameA = FilterName::fromString('users.email');
        $nameB = FilterName::fromString('orders.email');
        $version1 = FilterVersion::fromInt(1);
        $version2 = FilterVersion::fromInt(2);
        $positions = $this->positions($layout, [1, 4, 7]);

        $driver->provision($nameA, $version1, $layout);
        $driver->provision($nameA, $version2, $layout);
        $driver->provision($nameB, $version1, $layout);
        $driver->add($nameA, $version1, $positions);

        self::assertTrue($driver->mightContain($nameA, $version1, $positions));
        self::assertFalse($driver->mightContain($nameA, $version2, $positions));
        self::assertFalse($driver->mightContain($nameB, $version1, $positions));
    }

    private function layout(): BloomLayout
    {
        return BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    private function version(): FilterVersion
    {
        return FilterVersion::fromInt(1);
    }

    /**
     * @param  list<int>  $values
     */
    private function positions(BloomLayout $layout, array $values): BitPositions
    {
        return BitPositions::forLayout($layout, $values);
    }
}
