<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Application\CoordinatedWriter;
use Kefyusuf\BloomGate\Application\CoordinatedWriterCompletionResult;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06AuthoritativeSet;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06BloomDriver;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06EventLog;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06FilterRegistry;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06GenerationContractStore;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06Normalizer;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryWriterSynchronizationContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisWriterSynchronizationContractFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../Support/Application/Wu06CoordinatedWriterFixtures.php';

#[Group('redis')]
final class CoordinatedWriterPersistenceParityTest extends TestCase
{
    public function test_same_service_completes_prepared_lifetime_against_memory_and_redis_persistence(): void
    {
        $memory = $this->runScenario(
            new MemoryWriterSynchronizationContractFixture,
            'memory',
            'd',
        );
        $redis = $this->runScenario(
            new RedisWriterSynchronizationContractFixture,
            'redis',
            'e',
        );

        self::assertSame($memory, $redis);
        self::assertSame(
            [
                'prepared_state' => 'P',
                'retry_state' => 'P',
                'targets' => [1, 2],
                'bloom_versions' => [1, 2],
                'count_before_completion' => 1,
                'completion' => 'released',
                'count_after_completion' => 0,
            ],
            $memory,
        );
    }

    /**
     * @return array{
     *     prepared_state: string,
     *     retry_state: string,
     *     targets: list<int>,
     *     bloom_versions: list<int>,
     *     count_before_completion: int,
     *     completion: string,
     *     count_after_completion: int
     * }
     */
    private function runScenario(
        WriterSynchronizationStoreContractFixture $fixture,
        string $suffix,
        string $tokenCharacter,
    ): array {
        $name = FilterName::fromString('wu06.persistence.'.$suffix);
        $epoch = SynchronizationEpoch::fromInt(1);
        $targets = SynchronizationTargetSet::fromVersions([
            FilterVersion::fromInt(1),
            FilterVersion::fromInt(2),
        ]);
        $fixture->putCoordination(
            $name,
            true,
            new SynchronizationState(
                revision: SynchronizationRevision::fromInt(1),
                phase: SynchronizationPhase::Steady,
                currentEpoch: $epoch,
                currentTargets: $targets,
                candidateVersion: null,
                drainingEpoch: null,
            ),
        );

        $events = new Wu06EventLog;
        $definition = new Wu06FilterDefinition(
            normalizer: new Wu06Normalizer($events),
            authoritativeSet: new Wu06AuthoritativeSet,
        );
        $registered = new RegisteredFilter(
            name: $name,
            definition: $definition,
            queryOptimizationEnabled: true,
            capacity: 1_000,
            falsePositiveRate: 0.01,
        );
        $contracts = new Wu06GenerationContractStore($events);
        $semantic = $this->semanticContract($definition);

        foreach ([1, 2] as $version) {
            $contracts->put(
                FilterVersion::fromInt($version),
                new ManagedGenerationDescriptor(
                    layout: BloomLayout::create(
                        128 * $version,
                        3,
                        ProbeAlgorithm::Sha256DoubleHashV1,
                    ),
                    semanticContract: $semantic,
                ),
            );
        }

        $driver = new Wu06BloomDriver($events);
        $writer = new CoordinatedWriter(
            registry: new Wu06FilterRegistry($registered),
            synchronization: $fixture->store(),
            contracts: $contracts,
            driver: $driver,
            probes: new BloomProbeGenerator,
            fingerprints: new SemanticFingerprintCalculator,
        );
        $token = WriterLeaseToken::fromString(
            str_repeat($tokenCharacter, 32),
        );

        $prepared = $writer->prepare(
            $name,
            $token,
            ['A', 'B'],
        );
        $retry = $writer->prepare(
            $name,
            $token,
            ['A', 'B'],
        );
        $countBeforeCompletion = $fixture->store()->activeWriterCount(
            $name,
            $epoch,
        );
        $completion = $prepared->authoritativeCommitted();
        $countAfterCompletion = $fixture->store()->activeWriterCount(
            $name,
            $epoch,
        );

        return [
            'prepared_state' => $this->leaseState($prepared->lease()->state()),
            'retry_state' => $this->leaseState($retry->lease()->state()),
            'targets' => array_map(
                static fn (FilterVersion $version): int => $version->value(),
                $prepared->lease()->targets()->versions(),
            ),
            'bloom_versions' => $driver->versions,
            'count_before_completion' => $countBeforeCompletion,
            'completion' => $completion === CoordinatedWriterCompletionResult::Released
                ? 'released'
                : 'cleanup_uncertain',
            'count_after_completion' => $countAfterCompletion,
        ];
    }

    private function semanticContract(
        Wu06FilterDefinition $definition,
    ): GenerationSemanticContract {
        $fingerprints = new SemanticFingerprintCalculator;

        return new GenerationSemanticContract(
            normalizationFingerprint: $fingerprints->normalization(
                $definition->normalizer()->identity(),
            ),
            authoritativeSetFingerprint: $fingerprints->authoritativeSet(
                $definition->authoritativeSet()->identity(),
            ),
            consistencyFingerprint: $fingerprints->consistency(
                $definition->consistency(),
            ),
        );
    }

    private function leaseState(WriterLeaseState $state): string
    {
        return match ($state) {
            WriterLeaseState::Acquired => 'A',
            WriterLeaseState::Prepared => 'P',
            WriterLeaseState::Released => 'R',
        };
    }
}
