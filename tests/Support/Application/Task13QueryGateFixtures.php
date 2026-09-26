<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownFilter;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use LogicException;
use Throwable;

final class Task13Normalizer implements ValueNormalizer
{
    public int $calls = 0;

    /** @var list<string|int> */
    public array $values = [];

    public function __construct(
        private ?Throwable $failure = null,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString('task13-normalizer@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        $this->calls++;
        $this->values[] = $value;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return NormalizedValue::fromBytes('normalized:'.(string) $value);
    }
}

final class Task13AuthoritativeSet implements AuthoritativeSet
{
    public int $existsCalls = 0;

    /** @var list<string> */
    public array $seenBytes = [];

    public function __construct(
        private bool $existsResult = false,
        private ?Throwable $failure = null,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString('task13-authoritative@1');
    }

    public function exists(NormalizedValue $value): bool
    {
        $this->existsCalls++;
        $this->seenBytes[] = $value->bytes();

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->existsResult;
    }

    public function values(): iterable
    {
        return [];
    }
}

final class Task13FilterDefinition implements FilterDefinition
{
    public int $normalizerResolutions = 0;

    public int $authoritativeSetResolutions = 0;

    public int $consistencyResolutions = 0;

    public function __construct(
        private ValueNormalizer $normalizer,
        private AuthoritativeSet $authoritativeSet,
        private ConsistencyContract $consistency = ConsistencyContract::PreAddV1,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function normalizer(): ValueNormalizer
    {
        $this->normalizerResolutions++;

        return $this->normalizer;
    }

    public function authoritativeSet(): AuthoritativeSet
    {
        $this->authoritativeSetResolutions++;

        return $this->authoritativeSet;
    }

    public function consistency(): ConsistencyContract
    {
        $this->consistencyResolutions++;

        return $this->consistency;
    }
}

final class Task13FilterRegistry implements FilterRegistry
{
    public int $globalChecks = 0;

    public int $getCalls = 0;

    public function __construct(
        private bool $globalEnabled,
        private ?RegisteredFilter $filter = null,
        private ?Throwable $failure = null,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function globalQueryOptimizationEnabled(): bool
    {
        $this->globalChecks++;

        return $this->globalEnabled;
    }

    public function get(FilterName $name): RegisteredFilter
    {
        $this->getCalls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->filter === null || $this->filter->name()->equals($name) === false) {
            throw new UnknownFilter('Unknown Task 13 filter.');
        }

        return $this->filter;
    }
}

final class Task13SnapshotReader implements ActiveGenerationSnapshotReader
{
    public int $calls = 0;

    public function __construct(
        private ?ActiveGenerationSnapshot $snapshot,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function readActive(FilterName $name): ?ActiveGenerationSnapshot
    {
        $this->calls++;

        return $this->snapshot;
    }
}

final class Task13GenerationContractStore implements GenerationContractStore
{
    public int $readCalls = 0;

    public function __construct(
        private ?ManagedGenerationDescriptor $descriptor,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function read(
        FilterName $name,
        FilterVersion $version,
    ): ?ManagedGenerationDescriptor {
        $this->readCalls++;

        return $this->descriptor;
    }

    public function bind(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $expectedLayout,
        GenerationSemanticContract $semanticContract,
    ): void {
        throw new LogicException('Task 13 query tests never bind generation contracts.');
    }
}

final class Task13AuthorizedProbe implements AuthorizedProbe
{
    public int $calls = 0;

    public ?QuerySafetyDescriptor $descriptor = null;

    public ?BitPositions $positions = null;

    public function __construct(
        private AuthorizedProbeResult $result,
        private ?Throwable $failure = null,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function probe(
        QuerySafetyDescriptor $descriptor,
        BitPositions $positions,
    ): AuthorizedProbeResult {
        $this->calls++;
        $this->descriptor = $descriptor;
        $this->positions = $positions;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}

final readonly class Task13Fixture
{
    public function __construct(
        public FilterName $name,
        public BloomLayout $layout,
        public Task13Normalizer $normalizer,
        public Task13AuthoritativeSet $authoritativeSet,
        public Task13FilterDefinition $definition,
        public Task13FilterRegistry $registry,
        public Task13SnapshotReader $snapshots,
        public Task13GenerationContractStore $contracts,
        public QuerySafetyDescriptorResolver $resolver,
        public Task13AuthorizedProbe $probe,
    ) {
        // Explicit test-fixture constructor body.
    }
}

function task13Fixture(
    AuthorizedProbeResult $probeResult,
    bool $authoritativeResult = false,
    bool $globalEnabled = true,
    bool $filterEnabled = true,
    ?Throwable $normalizerFailure = null,
    ?Throwable $authoritativeFailure = null,
    ?Throwable $probeFailure = null,
    ?Throwable $registryFailure = null,
    bool $withActiveSnapshot = true,
    int $capacity = 1_000_000,
    float $falsePositiveRate = 0.001,
): Task13Fixture {
    $name = FilterName::fromString('users.email');
    $version = FilterVersion::fromInt(4);
    $layout = BloomLayout::create(
        64,
        3,
        ProbeAlgorithm::Sha256DoubleHashV1,
    );
    $normalizer = new Task13Normalizer($normalizerFailure);
    $authoritativeSet = new Task13AuthoritativeSet(
        $authoritativeResult,
        $authoritativeFailure,
    );
    $definition = new Task13FilterDefinition(
        $normalizer,
        $authoritativeSet,
    );
    $registered = new RegisteredFilter(
        name: $name,
        definition: $definition,
        queryOptimizationEnabled: $filterEnabled,
        capacity: $capacity,
        falsePositiveRate: $falsePositiveRate,
    );
    $registry = new Task13FilterRegistry(
        globalEnabled: $globalEnabled,
        filter: $registered,
        failure: $registryFailure,
    );

    $fingerprints = new SemanticFingerprintCalculator;
    $semanticContract = new GenerationSemanticContract(
        normalizationFingerprint: $fingerprints->normalization(
            $normalizer->identity(),
        ),
        authoritativeSetFingerprint: $fingerprints->authoritativeSet(
            $authoritativeSet->identity(),
        ),
        consistencyFingerprint: $fingerprints->consistency(
            $definition->consistency(),
        ),
    );

    $snapshot = $withActiveSnapshot
        ? new ActiveGenerationSnapshot(
            filterName: $name,
            revision: FilterStateRevision::fromInt(9),
            activeVersion: $version,
            lifecycle: LifecycleState::Active,
            health: HealthState::Healthy,
        )
        : null;
    $snapshots = new Task13SnapshotReader($snapshot);
    $contracts = new Task13GenerationContractStore(
        new ManagedGenerationDescriptor(
            layout: $layout,
            semanticContract: $semanticContract,
        ),
    );
    $resolver = new QuerySafetyDescriptorResolver(
        $snapshots,
        $contracts,
    );
    $probe = new Task13AuthorizedProbe(
        result: $probeResult,
        failure: $probeFailure,
    );

    return new Task13Fixture(
        name: $name,
        layout: $layout,
        normalizer: $normalizer,
        authoritativeSet: $authoritativeSet,
        definition: $definition,
        registry: $registry,
        snapshots: $snapshots,
        contracts: $contracts,
        resolver: $resolver,
        probe: $probe,
    );
}
