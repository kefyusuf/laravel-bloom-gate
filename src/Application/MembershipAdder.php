<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;

final readonly class MembershipAdder
{
    public function __construct(
        private FilterRegistry $registry,
        private ActiveGenerationSnapshotReader $snapshots,
        private GenerationContractStore $contracts,
        private BulkBloomDriver $driver,
        private BloomProbeGenerator $probes,
        private SemanticFingerprintCalculator $fingerprints,
    ) {}

    public function add(
        string $filter,
        string|int $value,
    ): void {
        $this->addMany($filter, [$value]);
    }

    /**
     * @param  iterable<string|int>  $values
     */
    public function addMany(
        string $filter,
        iterable $values,
    ): void {
        $name = FilterName::fromString($filter);
        $registered = $this->registry->get($name);
        $snapshot = $this->snapshots->readActive($name);

        if ($snapshot === null) {
            return;
        }

        if (
            $snapshot->filterName()->equals($name) === false
            || $snapshot->lifecycle() !== LifecycleState::Active
        ) {
            throw new InvalidConfiguration(
                'Active generation snapshot is not valid for managed membership synchronization.',
            );
        }

        $definition = $registered->definition();
        $consistency = $definition->consistency();

        if ($consistency === ConsistencyContract::ImmutableV1) {
            throw new ConsistencyContractViolation(
                'Immutable managed filters do not accept membership synchronization writes.',
            );
        }

        $normalizer = $definition->normalizer();
        $authoritativeSet = $definition->authoritativeSet();
        $expectedContract = new GenerationSemanticContract(
            normalizationFingerprint: $this->fingerprints->normalization(
                $normalizer->identity(),
            ),
            authoritativeSetFingerprint: $this->fingerprints->authoritativeSet(
                $authoritativeSet->identity(),
            ),
            consistencyFingerprint: $this->fingerprints->consistency(
                $consistency,
            ),
        );
        $managed = $this->contracts->read(
            $name,
            $snapshot->activeVersion(),
        );

        if ($managed === null) {
            throw new InvalidConfiguration(
                'Active managed generation has no bound semantic contract.',
            );
        }

        if ($managed->semanticContract()->equals($expectedContract) === false) {
            throw new InvalidConfiguration(
                'Active managed generation semantic contract does not match the runtime filter definition.',
            );
        }

        $items = [];

        foreach ($values as $value) {
            $items[] = $this->probes->generate(
                $normalizer->normalize($value),
                $managed->layout(),
            );
        }

        if ($items === []) {
            return;
        }

        $this->driver->addMany(
            $name,
            $snapshot->activeVersion(),
            $items,
        );
    }
}
