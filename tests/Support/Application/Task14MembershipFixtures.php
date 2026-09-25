<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
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
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use LogicException;
use Throwable;

final class Task14Normalizer implements ValueNormalizer
{
    public int $calls = 0;

    /** @var list<string|int> */
    public array $values = [];

    public function __construct(
        private ?Throwable $failure = null,
        private string $identity = 'task14-normalizer@1',
    ) {}

    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString($this->identity);
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

final class Task14AuthoritativeSet implements AuthoritativeSet
{
    public function __construct(
        private string $identity = 'task14-authoritative@1',
    ) {}

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString($this->identity);
    }

    public function exists(NormalizedValue $value): bool
    {
        throw new LogicException('Task 14 membership writes never perform authoritative lookup.');
    }

    public function values(): iterable
    {
        return [];
    }
}

final readonly class Task14FilterDefinition implements FilterDefinition
{
    public function __construct(
        private ValueNormalizer $normalizer,
        private AuthoritativeSet $authoritativeSet,
        private ConsistencyContract $consistency,
    ) {}

    public function normalizer(): ValueNormalizer
    {
        return $this->normalizer;
    }

    public function authoritativeSet(): AuthoritativeSet
    {
        return $this->authoritativeSet;
    }

    public function consistency(): ConsistencyContract
    {
        return $this->consistency;
    }
}

final class Task14FilterRegistry implements FilterRegistry
{
    public int $globalChecks = 0;

    public int $getCalls = 0;

    public function __construct(
        private RegisteredFilter $filter,
        private bool $globalEnabled = true,
        private ?Throwable $failure = null,
    ) {}

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

        return $this->filter;
    }
}

final class Task14SnapshotReader implements ActiveGenerationSnapshotReader
{
    public int $calls = 0;

    public function __construct(
        private ?ActiveGenerationSnapshot $snapshot,
        private ?Throwable $failure = null,
    ) {}

    public function readActive(FilterName $name): ?ActiveGenerationSnapshot
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->snapshot;
    }
}

final class Task14GenerationContractStore implements GenerationContractStore
{
    public int $readCalls = 0;

    public function __construct(
        private ?ManagedGenerationDescriptor $descriptor,
        private ?Throwable $failure = null,
    ) {}

    public function read(
        FilterName $name,
        FilterVersion $version,
    ): ?ManagedGenerationDescriptor {
        $this->readCalls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->descriptor;
    }

    public function bind(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $expectedLayout,
        GenerationSemanticContract $semanticContract,
    ): void {
        throw new LogicException('Task 14 membership writes never bind generation contracts.');
    }
}

final class Task14BulkBloomDriver implements BulkBloomDriver
{
    public int $addCalls = 0;

    public int $addManyCalls = 0;

    public ?FilterName $name = null;

    public ?FilterVersion $version = null;

    /** @var list<BitPositions> */
    public array $items = [];

    public function __construct(
        private ?Throwable $failure = null,
    ) {}

    public function provision(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
    ): void {
        throw new LogicException('Task 14 membership writes never provision.');
    }

    public function add(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): void {
        $this->addCalls++;

        throw new LogicException('Task 14 must route package writes through addMany.');
    }

    public function addMany(
        FilterName $name,
        FilterVersion $version,
        array $items,
    ): void {
        $this->addManyCalls++;
        $this->name = $name;
        $this->version = $version;
        $this->items = $items;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function mightContain(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): bool {
        throw new LogicException('Task 14 membership writes never query membership.');
    }

    public function destroy(
        FilterName $name,
        FilterVersion $version,
    ): void {
        throw new LogicException('Task 14 membership writes never destroy generations.');
    }
}

final readonly class Task14Fixture
{
    public function __construct(
        public FilterName $name,
        public BloomLayout $layout,
        public Task14Normalizer $normalizer,
        public Task14AuthoritativeSet $authoritativeSet,
        public Task14FilterDefinition $definition,
        public Task14FilterRegistry $registry,
        public Task14SnapshotReader $snapshots,
        public Task14GenerationContractStore $contracts,
        public Task14BulkBloomDriver $driver,
    ) {}
}

function task14Fixture(
    ConsistencyContract $consistency = ConsistencyContract::PreAddV1,
    bool $withActiveSnapshot = true,
    HealthState $health = HealthState::Healthy,
    bool $globalEnabled = true,
    bool $filterEnabled = true,
    ?Throwable $normalizerFailure = null,
    ?Throwable $registryFailure = null,
    ?Throwable $snapshotFailure = null,
    ?Throwable $contractFailure = null,
    ?Throwable $driverFailure = null,
    string $normalizerIdentity = 'task14-normalizer@1',
    string $authoritativeIdentity = 'task14-authoritative@1',
    ?GenerationSemanticContract $persistedContract = null,
    ?BloomLayout $layout = null,
): Task14Fixture {
    $name = FilterName::fromString('users.email');
    $version = FilterVersion::fromInt(4);
    $layout ??= BloomLayout::create(
        64,
        3,
        ProbeAlgorithm::Sha256DoubleHashV1,
    );
    $normalizer = new Task14Normalizer(
        failure: $normalizerFailure,
        identity: $normalizerIdentity,
    );
    $authoritativeSet = new Task14AuthoritativeSet($authoritativeIdentity);
    $definition = new Task14FilterDefinition(
        normalizer: $normalizer,
        authoritativeSet: $authoritativeSet,
        consistency: $consistency,
    );
    $registered = new RegisteredFilter(
        name: $name,
        definition: $definition,
        queryOptimizationEnabled: $filterEnabled,
        capacity: 1_000_000,
        falsePositiveRate: 0.001,
    );
    $registry = new Task14FilterRegistry(
        filter: $registered,
        globalEnabled: $globalEnabled,
        failure: $registryFailure,
    );
    $snapshot = $withActiveSnapshot
        ? new ActiveGenerationSnapshot(
            filterName: $name,
            revision: FilterStateRevision::fromInt(9),
            activeVersion: $version,
            lifecycle: LifecycleState::Active,
            health: $health,
        )
        : null;
    $snapshots = new Task14SnapshotReader(
        snapshot: $snapshot,
        failure: $snapshotFailure,
    );

    $fingerprints = new SemanticFingerprintCalculator;
    $runtimeContract = new GenerationSemanticContract(
        normalizationFingerprint: $fingerprints->normalization(
            $normalizer->identity(),
        ),
        authoritativeSetFingerprint: $fingerprints->authoritativeSet(
            $authoritativeSet->identity(),
        ),
        consistencyFingerprint: $fingerprints->consistency($consistency),
    );
    $contracts = new Task14GenerationContractStore(
        descriptor: new ManagedGenerationDescriptor(
            layout: $layout,
            semanticContract: $persistedContract ?? $runtimeContract,
        ),
        failure: $contractFailure,
    );

    return new Task14Fixture(
        name: $name,
        layout: $layout,
        normalizer: $normalizer,
        authoritativeSet: $authoritativeSet,
        definition: $definition,
        registry: $registry,
        snapshots: $snapshots,
        contracts: $contracts,
        driver: new Task14BulkBloomDriver($driverFailure),
    );
}
