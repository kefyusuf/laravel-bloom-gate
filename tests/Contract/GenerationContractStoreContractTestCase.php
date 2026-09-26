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
    abstract protected function driver(): BloomDriver;

    abstract protected function inspector(): BloomGenerationInspector;

    abstract protected function store(): GenerationContractStore;

    public function test_missing_generation_is_explicit_in_inspection(): void
    {
        self::assertNull($this->inspector()->layout($this->filterName(), $this->version()));
    }

    public function test_inspector_returns_the_exact_provisioned_layout_semantics(): void
    {
        $layout = $this->layout();

        $this->driver()->provision($this->filterName(), $this->version(), $layout);

        $inspected = $this->inspector()->layout($this->filterName(), $this->version());

        self::assertNotNull($inspected);
        self::assertTrue($layout->equals($inspected));
    }

    public function test_missing_generation_and_unbound_generation_are_distinct(): void
    {
        try {
            $this->store()->read($this->filterName(), $this->version());

            self::fail('Expected missing generation read to fail.');
        } catch (BloomFilterNotProvisioned) {
            $this->driver()->provision($this->filterName(), $this->version(), $this->layout());

            self::assertNull($this->store()->read($this->filterName(), $this->version()));
        }
    }

    public function test_bind_requires_an_existing_provisioned_generation(): void
    {
        $this->expectException(BloomFilterNotProvisioned::class);

        $this->store()->bind(
            $this->filterName(),
            $this->version(),
            $this->layout(),
            $this->semanticContract(),
        );
    }

    public function test_bind_rejects_layout_that_differs_from_actual_storage(): void
    {
        $this->driver()->provision($this->filterName(), $this->version(), $this->layout());

        $this->expectException(BloomLayoutMismatch::class);

        $this->store()->bind(
            $this->filterName(),
            $this->version(),
            BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1),
            $this->semanticContract(),
        );
    }

    public function test_bind_then_read_returns_actual_layout_and_semantic_contract(): void
    {
        $layout = $this->layout();
        $contract = $this->semanticContract();

        $this->driver()->provision($this->filterName(), $this->version(), $layout);
        $this->store()->bind($this->filterName(), $this->version(), $layout, $contract);

        $descriptor = $this->store()->read($this->filterName(), $this->version());

        self::assertNotNull($descriptor);
        self::assertTrue($layout->equals($descriptor->layout()));
        self::assertTrue($contract->equals($descriptor->semanticContract()));
    }

    public function test_equal_rebind_is_idempotent(): void
    {
        $layout = $this->layout();
        $contract = $this->semanticContract();

        $this->driver()->provision($this->filterName(), $this->version(), $layout);
        $this->store()->bind($this->filterName(), $this->version(), $layout, $contract);
        $this->store()->bind($this->filterName(), $this->version(), $layout, $contract);

        $descriptor = $this->store()->read($this->filterName(), $this->version());

        self::assertNotNull($descriptor);
        self::assertTrue($contract->equals($descriptor->semanticContract()));
    }

    public function test_different_rebind_conflicts_and_preserves_winner(): void
    {
        $layout = $this->layout();
        $winner = $this->semanticContract();
        $loser = $this->semanticContractFromSeeds('d', 'e', 'f');

        $this->driver()->provision($this->filterName(), $this->version(), $layout);
        $this->store()->bind($this->filterName(), $this->version(), $layout, $winner);

        try {
            $this->store()->bind($this->filterName(), $this->version(), $layout, $loser);

            self::fail('Expected conflicting generation semantic rebind.');
        } catch (GenerationContractConflict) {
            $descriptor = $this->store()->read($this->filterName(), $this->version());

            self::assertNotNull($descriptor);
            self::assertTrue($winner->equals($descriptor->semanticContract()));
        }
    }

    public function test_sibling_versions_have_independent_bindings(): void
    {
        $layout = $this->layout();
        $versionOne = FilterVersion::fromInt(1);
        $versionTwo = FilterVersion::fromInt(2);
        $first = $this->semanticContract();
        $second = $this->semanticContractFromSeeds('4', '5', '6');

        $this->driver()->provision($this->filterName(), $versionOne, $layout);
        $this->driver()->provision($this->filterName(), $versionTwo, $layout);
        $this->store()->bind($this->filterName(), $versionOne, $layout, $first);
        $this->store()->bind($this->filterName(), $versionTwo, $layout, $second);

        $descriptorOne = $this->store()->read($this->filterName(), $versionOne);
        $descriptorTwo = $this->store()->read($this->filterName(), $versionTwo);

        self::assertNotNull($descriptorOne);
        self::assertNotNull($descriptorTwo);
        self::assertTrue($first->equals($descriptorOne->semanticContract()));
        self::assertTrue($second->equals($descriptorTwo->semanticContract()));
    }

    public function test_destroyed_storage_cannot_present_a_managed_descriptor(): void
    {
        $layout = $this->layout();

        $this->driver()->provision($this->filterName(), $this->version(), $layout);
        $this->store()->bind(
            $this->filterName(),
            $this->version(),
            $layout,
            $this->semanticContract(),
        );
        $this->driver()->destroy($this->filterName(), $this->version());

        self::assertNull($this->inspector()->layout($this->filterName(), $this->version()));

        $this->expectException(BloomFilterNotProvisioned::class);

        $this->store()->read($this->filterName(), $this->version());
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

    protected function semanticContract(): GenerationSemanticContract
    {
        return $this->semanticContractFromSeeds('a', 'b', 'c');
    }

    protected function semanticContractFromSeeds(
        string $normalization,
        string $authoritativeSet,
        string $consistency,
    ): GenerationSemanticContract {
        return new GenerationSemanticContract(
            normalizationFingerprint: NormalizationFingerprint::fromString(
                'sha256:'.str_repeat($normalization, 64),
            ),
            authoritativeSetFingerprint: AuthoritativeSetFingerprint::fromString(
                'sha256:'.str_repeat($authoritativeSet, 64),
            ),
            consistencyFingerprint: ConsistencyFingerprint::fromString(
                'sha256:'.str_repeat($consistency, 64),
            ),
        );
    }
}
