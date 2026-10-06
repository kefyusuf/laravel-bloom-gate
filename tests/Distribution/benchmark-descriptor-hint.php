<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Kefyusuf\BloomGate\Application\ExistenceResult;
use Kefyusuf\BloomGate\Application\QueryGate;
use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;

/** Experimental fixture metadata hints. No lookup answers or negative results are cached. */
final class BenchmarkSnapshotHint implements ActiveGenerationSnapshotReader
{
    /** @var array<string, ActiveGenerationSnapshot> */
    private array $hints = [];

    public function __construct(private readonly ActiveGenerationSnapshotReader $inner) {}

    public function readActive(FilterName $name): ?ActiveGenerationSnapshot
    {
        if (isset($this->hints[$name->value()])) {
            return $this->hints[$name->value()];
        }
        $snapshot = $this->inner->readActive($name);
        if ($snapshot !== null) {
            $this->hints[$name->value()] = $snapshot;
        }

        return $snapshot;
    }

    public function clear(): void
    {
        $this->hints = [];
    }
}

final class BenchmarkContractHint implements GenerationContractStore
{
    /** @var array<string, ManagedGenerationDescriptor> */
    private array $hints = [];

    public function __construct(private readonly GenerationContractStore $inner) {}

    public function read(FilterName $name, FilterVersion $version): ?ManagedGenerationDescriptor
    {
        $key = $name->value().':'.$version->value();
        if (isset($this->hints[$key])) {
            return $this->hints[$key];
        }
        $contract = $this->inner->read($name, $version);
        if ($contract !== null) {
            $this->hints[$key] = $contract;
        }

        return $contract;
    }

    public function bind(FilterName $name, FilterVersion $version, BloomLayout $expectedLayout,
        GenerationSemanticContract $semanticContract): void
    {
        $this->inner->bind($name, $version, $expectedLayout, $semanticContract);
        $this->clear();
    }

    public function clear(): void
    {
        $this->hints = [];
    }
}

final readonly class BenchmarkDescriptorHint
{
    private BenchmarkSnapshotHint $snapshots;

    private BenchmarkContractHint $contracts;

    private QueryGate $gate;

    public function __construct(Application $app)
    {
        $this->snapshots = new BenchmarkSnapshotHint($app->make(ActiveGenerationSnapshotReader::class));
        $this->contracts = new BenchmarkContractHint($app->make(GenerationContractStore::class));
        // The original resolver still compares current application semantics on every lookup.
        $this->gate = new QueryGate($app->make(FilterRegistry::class),
            new QuerySafetyDescriptorResolver($this->snapshots, $this->contracts),
            $app->make(AuthorizedProbe::class), $app->make(BloomProbeGenerator::class),
            $app->make(SemanticFingerprintCalculator::class));
    }

    public function clear(): void
    {
        $this->snapshots->clear();
        $this->contracts->clear();
    }

    public function existsResult(string $filter, string $value): ExistenceResult
    {
        // Every lookup executes the unchanged atomic production authorization/probe script.
        $result = $this->gate->existsResult($filter, $value);
        if ($result->membership() === Membership::Bypassed) {
            $this->clear();
        }

        return $result;
    }
}
