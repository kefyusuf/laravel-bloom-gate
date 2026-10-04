<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Application\AdoptionHandoff;
use Kefyusuf\BloomGate\Application\CoordinatedFilterAdopter;
use Kefyusuf\BloomGate\Application\OnlineRebuildCoordinator;
use Kefyusuf\BloomGate\Application\OptimalBloomSizingV1;
use Kefyusuf\BloomGate\Application\RebuildProgress;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryGenerationContractStore;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationEvidenceApplier;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;
use Kefyusuf\BloomGate\Tests\Support\Application\InterleavingLifecycleStore;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StaticFilterRegistry;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task17FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Parity\CoordinationParityHarness;
use Kefyusuf\BloomGate\Tests\Support\Parity\MemoryCoordinationParityHarness;
use Kefyusuf\BloomGate\Tests\Support\Parity\RedisCoordinationParityHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../Support/Application/Task9BuildFixtures.php';

#[Group('redis')]
final class RebuildInterruptionParityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function cuts(): iterable
    {
        foreach (['published', 'verified', 'promoted', 'contracted', 'abort_requested', 'abort_rotated', 'abort_retired', 'partial_reconcile', 'advance_race', 'abort_race'] as $cut) {
            yield $cut => [$cut];
        }
    }

    #[DataProvider('cuts')]
    public function test_restart_resumes_durable_state_on_memory_and_redis(string $cut): void
    {
        $memory = $this->runScenario(new MemoryCoordinationParityHarness, $cut);
        $redis = $this->runScenario(new RedisCoordinationParityHarness, $cut);
        self::assertSame($memory, $redis);
    }

    /** @return array{int, int, string} */
    private function runScenario(CoordinationParityHarness $harness, string $cut): array
    {
        $name = FilterName::fromString('wu12.rebuild');
        $harness->track($name);
        try {
            $definition = new Task17FilterDefinition(['one', 'two', 'three'], ConsistencyContract::PreAddV1);
            $registry = new Task9StaticFilterRegistry(new RegisteredFilter($name, $definition, true, 1000, 0.01));
            $driver = new MemoryBloomDriver;
            $contracts = new MemoryGenerationContractStore($driver);
            $probes = new BloomProbeGenerator;
            $fingerprints = new SemanticFingerprintCalculator;
            $version = FilterVersion::fromInt(1);
            $layout = BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1);
            $driver->provision($name, $version, $layout);
            $contracts->bind($name, $version, $layout, new GenerationSemanticContract(
                $fingerprints->normalization($definition->normalizer()->identity()),
                $fingerprints->authoritativeSet($definition->authoritativeSet()->identity()),
                $fingerprints->consistency(ConsistencyContract::PreAddV1),
            ));
            $harness->putControl($name, new FilterControlState($name, FilterStateRevision::fromInt(1), $version, $version, null, [new GenerationControlState($version, LifecycleState::Active, HealthState::Healthy)]));
            (new CoordinatedFilterAdopter($harness->lifecycle()))->adopt($name, AdoptionHandoff::Quiescent);
            $interleaving = new InterleavingLifecycleStore($harness->lifecycle());
            $factory = static fn (): OnlineRebuildCoordinator => new OnlineRebuildCoordinator(
                $registry, new OptimalBloomSizingV1, $interleaving, $harness->writer(), $driver, $contracts,
                $probes, $fingerprints, new LifecycleTransitionPolicy, new ActivationVerificationEvidenceApplier,
                new ActivationVerifier($probes, $driver), 1,
            );
            $abort = str_starts_with($cut, 'abort');
            $heldToken = WriterLeaseToken::fromString(str_repeat('f', 32));
            if ($cut === 'abort_requested') {
                $harness->writer()->acquire($name, $heldToken);
            }
            $setupPhase = in_array($cut, ['advance_race', 'abort_requested'], true) ? SynchronizationPhase::DrainingPreReconcile : SynchronizationPhase::Reconciling;
            for ($step = 0; $step < 12 && $harness->lifecycle()->read($name)->synchronization()?->phase() !== $setupPhase; $step++) {
                // Publication itself is the first crash boundary.
                if ($cut === 'published' && $harness->lifecycle()->read($name)->control()?->candidateVersion() !== null) {
                    break;
                }
                self::assertSame(RebuildProgress::Advanced, $factory()->advance($name));
            }

            if (in_array($cut, ['contracted', 'abort_rotated'], true)) {
                $held = $harness->writer()->acquire($name, $heldToken);
                $harness->writer()->markPrepared($name, $heldToken);
                self::assertSame([1, 2], array_map(static fn (FilterVersion $target): int => $target->value(), $held->targets()->versions()));
            }

            $interrupted = false;
            if (str_ends_with($cut, '_race')) {
                $interleaving->beforeSynchronization = function () use ($factory, $name, $abort, &$interrupted): void {
                    self::assertSame(RebuildProgress::Advanced, $abort ? $factory()->abort($name) : $factory()->advance($name));
                    $interrupted = true;
                };
            } elseif ($cut === 'partial_reconcile') {
                $definition->authoritativeSet->throwAfter = 1;
            } else {
                $interleaving->afterMutation = static function (CoordinatedLifecycleSnapshot $snapshot) use ($cut, &$interrupted): void {
                    $control = $snapshot->control();
                    $sync = $snapshot->synchronization();
                    $matches = match ($cut) {
                        'published' => $sync?->phase() === SynchronizationPhase::DrainingPreReconcile,
                        'verified' => $sync?->phase() === SynchronizationPhase::Reconciling && $control?->generations()[1]->lifecycle() === LifecycleState::Verified,
                        'promoted' => $control?->activeVersion()?->value() === 2 && $sync?->phase() === SynchronizationPhase::ReadyToPromote,
                        'contracted' => $sync?->phase() === SynchronizationPhase::DrainingPostPromotion,
                        'abort_requested' => $sync?->phase() === SynchronizationPhase::AbortRequested,
                        'abort_rotated' => $sync?->phase() === SynchronizationPhase::DrainingAbort && $control?->candidateVersion() !== null,
                        'abort_retired' => $sync?->phase() === SynchronizationPhase::DrainingAbort && $control?->candidateVersion() === null,
                        default => false,
                    };
                    if ($matches && ! $interrupted) {
                        $interrupted = true;
                        throw new RuntimeException('Simulated process interruption.');
                    }
                };
            }

            for ($step = 0; $step < 15; $step++) {
                try {
                    $progress = $abort ? $factory()->abort($name) : $factory()->advance($name);
                    self::assertNotSame(RebuildProgress::RecoveryRequired, $progress);
                    if ($progress === RebuildProgress::Completed) {
                        break;
                    }
                } catch (CoordinationWriteConflict) {
                    self::assertTrue(str_ends_with($cut, '_race'));
                    $interleaving->beforeSynchronization = null;
                } catch (RuntimeException $failure) {
                    if ($cut === 'partial_reconcile') {
                        self::assertStringContainsString('traversal failure', $failure->getMessage());
                        self::assertSame(SynchronizationPhase::Reconciling, $harness->lifecycle()->read($name)->synchronization()?->phase());
                        $definition->authoritativeSet->throwAfter = null;
                        $interrupted = true;
                    } else {
                        self::assertSame('Simulated process interruption.', $failure->getMessage());
                        if (in_array($cut, ['abort_requested', 'contracted', 'abort_rotated'], true)) {
                            $held = $harness->writer()->readLease($name, $heldToken);
                            self::assertNotNull($held);
                            self::assertSame(1, $harness->writer()->activeWriterCount($name, $held->epoch()));
                            self::assertSame(RebuildProgress::Blocked, $abort ? $factory()->abort($name) : $factory()->advance($name));
                            if ($cut === 'abort_requested') {
                                self::assertSame(RebuildProgress::RecoveryRequired, $factory()->advance($name));
                            }
                            $harness->writer()->release($name, $heldToken);
                        }
                    }
                    $interleaving->afterMutation = null;
                }
            }
            self::assertTrue($interrupted, 'The specified crash/race boundary must be reached.');
            $snapshot = $harness->lifecycle()->read($name);
            self::assertNull($snapshot->control()?->candidateVersion());
            self::assertSame(SynchronizationPhase::Steady, $snapshot->synchronization()?->phase());
            self::assertSame($abort ? 1 : 2, $snapshot->control()?->activeVersion()?->value());
            $active = $snapshot->control()->activeVersion();
            if (! $abort) {
                $descriptor = $contracts->read($name, $active);
                self::assertNotNull($descriptor);
                foreach ($definition->authoritativeSet->values as $value) {
                    self::assertTrue($driver->mightContain($name, $active, $probes->generate($definition->normalizer()->normalize($value), $descriptor->layout())));
                }
            }

            return [$active->value(), $snapshot->synchronization()->currentEpoch()->value(), $snapshot->synchronization()->phase()->name];
        } finally {
            $harness->cleanup();
        }
    }
}
