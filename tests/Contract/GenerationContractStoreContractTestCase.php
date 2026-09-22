<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractConflict;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use PHPUnit\Framework\TestCase;

abstract class GenerationContractStoreContractTestCase extends TestCase
{
    abstract protected function makeDriver(): BloomDriver&BloomGenerationInspector;

    abstract protected function makeStore(
        BloomGenerationInspector $inspector,
    ): GenerationContractStore;

    public function test_missing_generation_is_explicit_in_inspection(): void
    {
        $driver = $this->makeDriver();

        self::assertNull($driver->layout($this->filterName(), $this->version()));
    }

    public function test_inspector_returns_the_exact_provisioned_layout_semantics(): void
    {
        $driver = $this->makeDriver();
        $layout = $this->layout();

        $driver->provision($this->filterName(), $this->version(), $layout);

        $inspected = $driver->layout($this->filterName(), $this->version());

        self::assertNotNull($inspected);
        self::assertTrue($layout->equals($inspected));
    }

    public function test_missing_generation_and_unbound_generation_are_distinct(): void
    {
        $driver = $this->makeDriver();
        $store = $this->makeStore($driver);

        try {
            $store->read($this->filterName(), $this->version());

            self::fail('Expected missing generation read to fail.');
        } catch (BloomFilterNotProvisioned) {
            $driver->provision($this->filterName(), $this->version(), $this->layout());

            self::assertNull($store->read($this->filterName(), $this->version()));
        }
    }

    public function test_bind_requires_an_existing_provisioned_generation(): void
    {
        $driver = $this->makeDriver();
        $store = $this->makeStore($driver);

        $this->expectException(BloomFilterNotProvisioned::class);

        $store->bind(
            $this->filterName(),
            $this->version(),
            $this->layout(),
            $this->semanticContract(),
        );
    }

    public function test_bind_rejects_layout_that_differs_from_actual_storage(): void
    {
        $driver = $this->makeDriver();
        $store = $this->makeStore($driver);
        $driver->provision($this->filterName(), $this->version(), $this->layout());

        $this->expectException(BloomLayoutMismatch::class);

        $store->bind(
            $this->filterName(),
            $this->version(),
            BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1),
            $this->semanticContract(),
        );
    }

    public function test_bind_then_read_returns_actual_layout_and_semantic_contract(): void
    {
        $driver = $this->makeDriver();
        $store = $this->makeStore($driver);
        $layout = $this->layout();
        $contract = $this->semanticContract();

        $driver->provision($this->filterName(), $this->version(), $layout);
        $store->bind($this->filterName(), $this->version(), $layout, $contract);

        $descriptor = $store->read($this->filterName(), $this->version());

        self::assertNotNull($descriptor);
        self::assertTrue($layout->equals($descriptor->layout()));
        self::assertTrue($contract->equals($descriptor->semanticContract()));
    }

    public function test_equal_rebind_is_idempotent(): void
    {
        $driver = $this->makeDriver();
        $store = $this->makeStore($driver);
        $layout = $this->layout();
        $contract = $this->semanticContract();

        $driver->provision($this->filterName(), $this->version(), $layout);
        $store->bind($this->filterName(), $this->version(), $layout, $contract);
        $store->bind($this->filterName(), $this->version(), $layout, $contract);

        $descriptor = $store->read($this->filterName(), $this->version());

        self::assertNotNull($descriptor);
        self::assertTrue($contract->equals($descriptor->semanticContract()));
    }

    public function test_different_rebind_conflicts_and_preserves_winner(): void
    {
        $driver = $this->makeDriver();
        $store = $this->makeStore($driver);
        $layout = $this->layout();
        $winner = $this->semanticContract();
        $loser = new GenerationSemanticContract(
            normalizationFingerprint: NormalizationFingerprint::fromString(
                'sha256:'.str_repeat('d', 64),
            ),
            authoritativeSetFingerprint: AuthoritativeSetFingerprint::fromString(
                'sha256:'.str_repeat('e', 64),
            ),
            consistencyFingerprint: ConsistencyFingerprint::fromString(
                'sha256:'.str_repeat('f', 64),
            ),
        );

        $driver->provision($this->filterName(), $this->version(), $layout);
        $store->bind($this->filterName(), $this->version(), $layout, $winner);

        try {
            $store->bind($this->filterName(), $this->version(), $layout, $loser);

            self::fail('Expected conflicting generation semantic rebind.');
        } catch (GenerationContractConflict) {
            $descriptor = $store->read($this->filterName(), $this->version());

            self::assertNotNull($descriptor);
            self::assertTrue($winner->equals($descriptor->semanticContract()));
        }
    }

    public function test_sibling_versions_have_independent_bindings(): void
    {
        $driver = $this->makeDriver();
        $store = $this->makeStore($driver);
        $layout = $this->layout();
        $versionOne = FilterVersion::fromInt(1);
        $versionTwo = FilterVersion::fromInt(2);
        $first = $this->semanticContract();
        $second = new GenerationSemanticContract(
            normalizationFingerprint: NormalizationFingerprint::fromString(
                'sha256:'.str_repeat('4', 64),
            ),
            authoritativeSetFingerprint: AuthoritativeSetFingerprint::fromString(
                'sha256:'.str_repeat('5', 64),
            ),
            consistencyFingerprint: ConsistencyFingerprint::fromString(
                'sha256:'.str_repeat('6', 64),
            ),
        );

        $driver->provision($this->filterName(), $versionOne, $layout);
        $driver->provision($this->filterName(), $versionTwo, $layout);
        $store->bind($this->filterName(), $versionOne, $layout, $first);
        $store->bind($this->filterName(), $versionTwo, $layout, $second);

        $descriptorOne = $store->read($this->filterName(), $versionOne);
        $descriptorTwo = $store->read($this->filterName(), $versionTwo);

        self::assertNotNull($descriptorOne);
        self::assertNotNull($descriptorTwo);
        self::assertTrue($first->equals($descriptorOne->semanticContract()));
        self::assertTrue($second->equals($descriptorTwo->semanticContract()));
    }

    public function test_destroyed_storage_cannot_present_a_managed_descriptor(): void
    {
        $driver = $this->makeDriver();
        $store = $this->makeStore($driver);
        $layout = $this->layout();

        $driver->provision($this->filterName(), $this->version(), $layout);
        $store->bind(
            $this->filterName(),
            $this->version(),
            $layout,
            $this->semanticContract(),
        );
        $driver->destroy($this->filterName(), $this->version());

        self::assertNull($driver->layout($this->filterName(), $this->version()));

        $this->expectException(BloomFilterNotProvisioned::class);

        $store->read($this->filterName(), $this->version());
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString('users.email');
    }

    private function version(): FilterVersion
    {
        return FilterVersion::fromInt(1);
    }

    private function layout(): BloomLayout
    {
        return BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
    }

    private function semanticContract(): GenerationSemanticContract
    {
        return new GenerationSemanticContract(
            normalizationFingerprint: NormalizationFingerprint::fromString(
                'sha256:'.str_repeat('a', 64),
            ),
            authoritativeSetFingerprint: AuthoritativeSetFingerprint::fromString(
                'sha256:'.str_repeat('b', 64),
            ),
            consistencyFingerprint: ConsistencyFingerprint::fromString(
                'sha256:'.str_repeat('c', 64),
            ),
        );
    }
}
