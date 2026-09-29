<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Throwable;
use UnexpectedValueException;

final readonly class CoordinatedWriter
{
    public function __construct(
        private FilterRegistry $registry,
        private WriterSynchronizationStore $synchronization,
        private GenerationContractStore $contracts,
        private BulkBloomDriver $driver,
        private BloomProbeGenerator $probes,
        private SemanticFingerprintCalculator $fingerprints,
    ) {}

    /**
     * @param  iterable<string|int>  $values
     */
    public function prepare(
        FilterName $name,
        WriterLeaseToken $token,
        iterable $values,
    ): PreparedCoordinatedWrite {
        try {
            $lease = $this->synchronization->acquire(
                $name,
                $token,
            );

            if ($lease->state() === WriterLeaseState::Prepared) {
                return $this->preparedWrite($name, $lease);
            }

            if ($lease->state() !== WriterLeaseState::Acquired) {
                throw new UnexpectedValueException(
                    'Coordinated writer acquisition did not return an ACQUIRED or PREPARED lease.',
                );
            }

            $registered = $this->registry->get($name);
            $definition = $registered->definition();

            if ($definition->consistency() !== ConsistencyContract::PreAddV1) {
                throw new UnexpectedValueException(
                    'Coordinated writer preparation requires preadd-v1 consistency.',
                );
            }

            $normalizer = $definition->normalizer();
            $authoritativeSet = $definition->authoritativeSet();
            $expectedSemantic = new GenerationSemanticContract(
                normalizationFingerprint: $this->fingerprints->normalization(
                    $normalizer->identity(),
                ),
                authoritativeSetFingerprint: $this->fingerprints->authoritativeSet(
                    $authoritativeSet->identity(),
                ),
                consistencyFingerprint: $this->fingerprints->consistency(
                    $definition->consistency(),
                ),
            );

            $targets = $this->targetDescriptors(
                $name,
                $lease,
                $expectedSemantic,
            );
            $normalized = $this->normalizeValues(
                $values,
                $normalizer,
            );

            foreach ($lease->targets()->versions() as $version) {
                $descriptor = $targets[$version->value()];
                $items = [];

                foreach ($normalized as $value) {
                    $items[] = $this->probes->generate(
                        $value,
                        $descriptor->layout(),
                    );
                }

                if ($items === []) {
                    continue;
                }

                $this->driver->addMany(
                    $name,
                    $version,
                    $items,
                );
            }

            $preparedLease = $this->synchronization->markPrepared(
                $name,
                $token,
            );

            if ($preparedLease->state() !== WriterLeaseState::Prepared) {
                throw new UnexpectedValueException(
                    'Coordinated writer preparation did not return a durable PREPARED lease.',
                );
            }

            if (
                $preparedLease->token()->equals($lease->token()) === false
                || $preparedLease->epoch()->equals($lease->epoch()) === false
                || $preparedLease->targets()->equals($lease->targets()) === false
            ) {
                throw new UnexpectedValueException(
                    'Prepared writer lease changed its acquisition binding.',
                );
            }

            return $this->preparedWrite(
                $name,
                $preparedLease,
            );
        } catch (CoordinatedWriterPreparationFailed $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw CoordinatedWriterPreparationFailed::because(
                filterName: $name,
                token: $token,
                message: 'Coordinated writer preparation failed before authoritative membership was permitted.',
                previous: $failure,
            );
        }
    }

    public function abandon(
        FilterName $name,
        WriterLeaseToken $token,
    ): CoordinatedWriterCompletionResult {
        try {
            $released = $this->synchronization->release(
                $name,
                $token,
            );
        } catch (Throwable) {
            return CoordinatedWriterCompletionResult::CleanupUncertain;
        }

        if ($released->state() !== WriterLeaseState::Released) {
            throw new UnexpectedValueException(
                'Coordinated writer abandonment did not return a terminal RELEASED lease.',
            );
        }

        return CoordinatedWriterCompletionResult::Released;
    }

    /**
     * @return array<int, ManagedGenerationDescriptor>
     */
    private function targetDescriptors(
        FilterName $name,
        WriterLease $lease,
        GenerationSemanticContract $expectedSemantic,
    ): array {
        $descriptors = [];

        foreach ($lease->targets()->versions() as $version) {
            $descriptor = $this->contracts->read(
                $name,
                $version,
            );

            if ($descriptor === null) {
                throw new UnexpectedValueException(
                    'Coordinated writer target has no bound generation semantics.',
                );
            }

            if (
                $descriptor->semanticContract()->equals(
                    $expectedSemantic,
                ) === false
            ) {
                throw new UnexpectedValueException(
                    'Coordinated writer target semantics do not match the runtime filter definition.',
                );
            }

            $descriptors[$version->value()] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * @param  iterable<string|int>  $values
     * @return list<NormalizedValue>
     */
    private function normalizeValues(
        iterable $values,
        \Kefyusuf\BloomGate\Contracts\ValueNormalizer $normalizer,
    ): array {
        $normalized = [];

        foreach ($values as $value) {
            $normalized[] = $normalizer->normalize($value);
        }

        return $normalized;
    }

    private function preparedWrite(
        FilterName $name,
        WriterLease $lease,
    ): PreparedCoordinatedWrite {
        return new PreparedCoordinatedWrite(
            filterName: $name,
            lease: $lease,
            synchronization: $this->synchronization,
        );
    }
}
