<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

use Kefyusuf\BloomGate\Application\AuthoritativeOutcomeUncertain;
use Kefyusuf\BloomGate\Application\CoordinatedWriter;
use Kefyusuf\BloomGate\Application\CoordinatedWriterCompletionResult;
use Kefyusuf\BloomGate\Application\CoordinatedWriterPreparationFailed;
use Kefyusuf\BloomGate\Application\PreparedCoordinatedWrite;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
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
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06AuthoritativeSet;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06BloomDriver;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06EventLog;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06FilterRegistry;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06GenerationContractStore;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06Normalizer;
use Kefyusuf\BloomGate\Tests\Support\Application\Wu06WriterSynchronizationStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__.'/../../Support/Application/Wu06CoordinatedWriterFixtures.php';

final class CoordinatedWriterTest extends TestCase
{
    public function test_token_exists_before_first_remote_operation(): void
    {
        $environment = $this->environment();
        $token = WriterLeaseToken::generate();

        $prepared = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['first'],
        );

        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{32}\z/',
            $token->value(),
        );
        self::assertSame(
            'store:acquire:'.$token->value(),
            $environment['events']->events[0] ?? null,
        );
        self::assertTrue($prepared->token()->equals($token));
    }

    public function test_acquire_failure_prevents_bloom_writes(): void
    {
        $environment = $this->environment();
        $token = $this->token('a');
        $environment['store']->acquireFailure = new CoordinationStoreOperationFailed(
            'acquire unavailable',
        );

        try {
            $environment['writer']->prepare(
                $environment['name'],
                $token,
                ['value'],
            );
            self::fail('Expected coordinated writer preparation failure.');
        } catch (CoordinatedWriterPreparationFailed $failure) {
            self::assertTrue($failure->token()->equals($token));
        }

        self::assertSame(0, $environment['driver']->addManyCalls);
        self::assertSame(0, $environment['store']->markPreparedCalls);
    }

    public function test_target_semantic_mismatch_prevents_authoritative_permission(): void
    {
        $environment = $this->environment();
        $mismatch = new Wu06FilterDefinition(
            normalizer: new Wu06Normalizer(
                $environment['events'],
                'wu06-mismatch-normalizer@1',
            ),
            authoritativeSet: new Wu06AuthoritativeSet,
        );
        $environment['contracts']->put(
            FilterVersion::fromInt(2),
            new ManagedGenerationDescriptor(
                layout: $this->layout(2),
                semanticContract: $this->semanticContract($mismatch),
            ),
        );

        $this->expectPreparationFailure(
            $environment,
            $this->token('b'),
        );

        self::assertSame(0, $environment['driver']->addManyCalls);
        self::assertSame(0, $environment['store']->markPreparedCalls);
    }

    public function test_required_target_bloom_failure_prevents_prepared_state(): void
    {
        $environment = $this->environment();
        $token = $this->token('c');
        $environment['driver']->failVersion = 2;

        $this->expectPreparationFailure($environment, $token);

        self::assertSame([1, 2], $environment['driver']->versions);
        self::assertSame(0, $environment['store']->markPreparedCalls);
        self::assertSame(
            WriterLeaseState::Acquired,
            $environment['store']->lease($token)?->state(),
        );
    }

    public function test_partial_target_writes_are_safe_false_positives_and_retryable(): void
    {
        $environment = $this->environment();
        $token = $this->token('d');
        $environment['driver']->failVersion = 2;

        $this->expectPreparationFailure($environment, $token);

        $environment['driver']->failVersion = null;
        $prepared = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );

        self::assertSame([1, 2, 1, 2], $environment['driver']->versions);
        self::assertSame(WriterLeaseState::Prepared, $prepared->lease()->state());
        self::assertSame(1, $environment['store']->countIncrements);
    }

    public function test_retry_with_same_acquired_token_reuses_original_target_binding(): void
    {
        $environment = $this->environment();
        $token = $this->token('e');
        $environment['driver']->failVersion = 2;

        $this->expectPreparationFailure($environment, $token);

        $environment['store']->rotate(
            SynchronizationEpoch::fromInt(2),
            SynchronizationTargetSet::fromVersions([
                FilterVersion::fromInt(3),
            ]),
        );
        $environment['contracts']->put(
            FilterVersion::fromInt(3),
            new ManagedGenerationDescriptor(
                layout: $this->layout(3),
                semanticContract: $environment['semantic'],
            ),
        );
        $environment['driver']->failVersion = null;

        $prepared = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );

        self::assertSame([1, 2], $this->targetValues($prepared));
        self::assertSame([1, 2, 1, 2], $environment['driver']->versions);
        self::assertSame(
            1,
            $environment['store']->activeWriterCount(
                $environment['name'],
                SynchronizationEpoch::fromInt(1),
            ),
        );
        self::assertSame(
            0,
            $environment['store']->activeWriterCount(
                $environment['name'],
                SynchronizationEpoch::fromInt(2),
            ),
        );
    }

    public function test_all_target_writes_complete_before_mark_prepared(): void
    {
        $environment = $this->environment();

        $environment['writer']->prepare(
            $environment['name'],
            $this->token('f'),
            ['value'],
        );

        $first = array_search('bloom:1', $environment['events']->events, true);
        $second = array_search('bloom:2', $environment['events']->events, true);
        $prepared = array_search('store:prepare', $environment['events']->events, true);

        self::assertIsInt($first);
        self::assertIsInt($second);
        self::assertIsInt($prepared);
        self::assertLessThan($prepared, $first);
        self::assertLessThan($prepared, $second);
    }

    public function test_uncertain_mark_prepared_acknowledgement_retries_same_token_and_converges_to_p(): void
    {
        $environment = $this->environment();
        $token = $this->token('0');
        $environment['store']->markPreparedPersistsBeforeFailure = true;
        $environment['store']->markPreparedFailure = new CoordinationStoreOperationFailed(
            'prepared acknowledgement unavailable',
        );

        $this->expectPreparationFailure($environment, $token);

        self::assertSame(
            WriterLeaseState::Prepared,
            $environment['store']->lease($token)?->state(),
        );
        self::assertSame(2, $environment['driver']->addManyCalls);
        self::assertSame(1, $environment['store']->countIncrements);
        self::assertSame(1, $environment['store']->markPreparedCalls);

        $environment['store']->markPreparedFailure = null;
        $environment['store']->markPreparedPersistsBeforeFailure = false;

        $prepared = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );

        self::assertSame(WriterLeaseState::Prepared, $prepared->lease()->state());
        self::assertSame(2, $environment['driver']->addManyCalls);
        self::assertSame(1, $environment['store']->countIncrements);
        self::assertSame(1, $environment['store']->markPreparedCalls);
    }

    public function test_prepared_retry_does_not_repeat_count_or_bloom_work(): void
    {
        $environment = $this->environment();
        $token = $this->token('1');

        $first = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );
        $retry = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );

        self::assertSame(WriterLeaseState::Prepared, $first->lease()->state());
        self::assertSame(WriterLeaseState::Prepared, $retry->lease()->state());
        self::assertSame(1, $environment['store']->countIncrements);
        self::assertSame(1, $environment['store']->markPreparedCalls);
        self::assertSame(2, $environment['driver']->addManyCalls);
    }

    public function test_only_durable_prepared_state_is_returned_as_authorization(): void
    {
        $environment = $this->environment();
        $token = $this->token('2');
        $environment['store']->markPreparedReturnsAcquired = true;

        $this->expectPreparationFailure($environment, $token);

        self::assertSame(
            WriterLeaseState::Acquired,
            $environment['store']->lease($token)?->state(),
        );
    }

    public function test_known_commit_releases_prepared_lease(): void
    {
        $environment = $this->environment();
        $token = $this->token('3');
        $prepared = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );

        $result = $prepared->authoritativeCommitted();

        self::assertSame(CoordinatedWriterCompletionResult::Released, $result);
        self::assertSame(
            WriterLeaseState::Released,
            $environment['store']->lease($token)?->state(),
        );
        self::assertSame(0, $this->activeCount($environment));
    }

    public function test_known_rollback_and_pre_authoritative_abort_release_p_or_a(): void
    {
        $preparedEnvironment = $this->environment();
        $preparedToken = $this->token('4');
        $prepared = $preparedEnvironment['writer']->prepare(
            $preparedEnvironment['name'],
            $preparedToken,
            ['value'],
        );

        self::assertSame(
            CoordinatedWriterCompletionResult::Released,
            $prepared->authoritativeAborted(),
        );

        $acquiredEnvironment = $this->environment();
        $acquiredToken = $this->token('5');
        $acquiredEnvironment['store']->acquire(
            $acquiredEnvironment['name'],
            $acquiredToken,
        );

        self::assertSame(
            CoordinatedWriterCompletionResult::Released,
            $acquiredEnvironment['writer']->abandon(
                $acquiredEnvironment['name'],
                $acquiredToken,
            ),
        );
        self::assertSame(
            WriterLeaseState::Released,
            $acquiredEnvironment['store']->lease($acquiredToken)?->state(),
        );
    }

    public function test_unknown_authoritative_outcome_never_releases(): void
    {
        $environment = $this->environment();
        $token = $this->token('6');
        $prepared = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );

        $failure = AuthoritativeOutcomeUncertain::forPrepared($prepared);

        self::assertTrue($failure->token()->equals($token));
        self::assertSame(0, $environment['store']->releaseCalls);
        self::assertSame(1, $this->activeCount($environment));
    }

    public function test_known_commit_with_uncertain_release_reports_cleanup_uncertainty(): void
    {
        $environment = $this->environment();
        $token = $this->token('7');
        $prepared = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );
        $environment['store']->releaseFailure = new CoordinationStoreOperationFailed(
            'release acknowledgement unavailable',
        );

        $result = $prepared->authoritativeCommitted();

        self::assertSame(
            CoordinatedWriterCompletionResult::CleanupUncertain,
            $result,
        );
        self::assertSame(
            WriterLeaseState::Prepared,
            $environment['store']->lease($token)?->state(),
        );
        self::assertSame(1, $this->activeCount($environment));
    }

    public function test_failed_prepare_preserves_original_token(): void
    {
        $environment = $this->environment();
        $token = $this->token('8');
        $environment['driver']->failVersion = 1;

        try {
            $environment['writer']->prepare(
                $environment['name'],
                $token,
                ['value'],
            );
            self::fail('Expected coordinated writer preparation failure.');
        } catch (CoordinatedWriterPreparationFailed $failure) {
            self::assertTrue($failure->token()->equals($token));
            self::assertTrue($failure->filterName()->equals($environment['name']));
        }
    }

    public function test_explicit_pre_authoritative_abandonment_uses_terminal_release(): void
    {
        $environment = $this->environment();
        $token = $this->token('9');
        $environment['store']->acquire($environment['name'], $token);

        $result = $environment['writer']->abandon(
            $environment['name'],
            $token,
        );

        self::assertSame(CoordinatedWriterCompletionResult::Released, $result);
        self::assertSame(1, $environment['store']->releaseCalls);
        self::assertSame(
            WriterLeaseState::Released,
            $environment['store']->lease($token)?->state(),
        );
        self::assertSame(0, $this->activeCount($environment));
    }

    public function test_abandonment_cleanup_uncertainty_is_reported_without_claiming_release(): void
    {
        $environment = $this->environment();
        $token = $this->token('b');
        $environment['store']->acquire($environment['name'], $token);
        $environment['store']->releaseFailure = new CoordinationStoreOperationFailed(
            'release unavailable',
        );

        self::assertSame(
            CoordinatedWriterCompletionResult::CleanupUncertain,
            $environment['writer']->abandon(
                $environment['name'],
                $token,
            ),
        );
        self::assertSame(
            WriterLeaseState::Acquired,
            $environment['store']->lease($token)?->state(),
        );
        self::assertSame(1, $this->activeCount($environment));
    }

    public function test_abandonment_does_not_mask_programming_failures_as_cleanup_uncertainty(): void
    {
        $environment = $this->environment();
        $token = $this->token('c');
        $environment['store']->acquire($environment['name'], $token);
        $environment['store']->releaseFailure = new \LogicException(
            'programming failure',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('programming failure');

        $environment['writer']->abandon(
            $environment['name'],
            $token,
        );
    }

    public function test_prepared_write_has_no_destructor_ttl_or_implicit_release(): void
    {
        $environment = $this->environment();
        $token = $this->token('a');
        $prepared = $environment['writer']->prepare(
            $environment['name'],
            $token,
            ['value'],
        );

        self::assertFalse(
            (new ReflectionClass(PreparedCoordinatedWrite::class))
                ->hasMethod('__destruct'),
        );

        unset($prepared);
        gc_collect_cycles();

        self::assertSame(0, $environment['store']->releaseCalls);
        self::assertSame(1, $this->activeCount($environment));
    }

    /**
     * @return array{
     *     name: FilterName,
     *     events: Wu06EventLog,
     *     contracts: Wu06GenerationContractStore,
     *     driver: Wu06BloomDriver,
     *     store: Wu06WriterSynchronizationStore,
     *     writer: CoordinatedWriter,
     *     semantic: GenerationSemanticContract
     * }
     */
    private function environment(): array
    {
        $name = FilterName::fromString('users.email');
        $events = new Wu06EventLog;
        $normalizer = new Wu06Normalizer($events);
        $definition = new Wu06FilterDefinition(
            normalizer: $normalizer,
            authoritativeSet: new Wu06AuthoritativeSet,
        );
        $registered = new RegisteredFilter(
            name: $name,
            definition: $definition,
            queryOptimizationEnabled: true,
            capacity: 1_000,
            falsePositiveRate: 0.01,
        );
        $registry = new Wu06FilterRegistry($registered);
        $semantic = $this->semanticContract($definition);
        $contracts = new Wu06GenerationContractStore($events);

        foreach ([1, 2] as $version) {
            $contracts->put(
                FilterVersion::fromInt($version),
                new ManagedGenerationDescriptor(
                    layout: $this->layout($version),
                    semanticContract: $semantic,
                ),
            );
        }

        $driver = new Wu06BloomDriver($events);
        $store = new Wu06WriterSynchronizationStore(
            events: $events,
            epoch: SynchronizationEpoch::fromInt(1),
            targets: SynchronizationTargetSet::fromVersions([
                FilterVersion::fromInt(1),
                FilterVersion::fromInt(2),
            ]),
        );
        $writer = new CoordinatedWriter(
            registry: $registry,
            synchronization: $store,
            contracts: $contracts,
            driver: $driver,
            probes: new BloomProbeGenerator,
            fingerprints: new SemanticFingerprintCalculator,
        );

        return compact(
            'name',
            'events',
            'contracts',
            'driver',
            'store',
            'writer',
            'semantic',
        );
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     store: Wu06WriterSynchronizationStore,
     *     writer: CoordinatedWriter,
     *     driver: Wu06BloomDriver
     * }  $environment
     */
    private function expectPreparationFailure(
        array $environment,
        WriterLeaseToken $token,
    ): void {
        try {
            $environment['writer']->prepare(
                $environment['name'],
                $token,
                ['value'],
            );
            self::fail('Expected coordinated writer preparation failure.');
        } catch (CoordinatedWriterPreparationFailed $failure) {
            self::assertTrue($failure->token()->equals($token));
        }
    }

    /**
     * @param  array{
     *     name: FilterName,
     *     store: Wu06WriterSynchronizationStore
     * }  $environment
     */
    private function activeCount(array $environment): int
    {
        return $environment['store']->activeWriterCount(
            $environment['name'],
            SynchronizationEpoch::fromInt(1),
        );
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

    private function token(string $character): WriterLeaseToken
    {
        return WriterLeaseToken::fromString(str_repeat($character, 32));
    }

    private function layout(int $version): BloomLayout
    {
        return BloomLayout::create(
            128 * $version,
            3,
            ProbeAlgorithm::Sha256DoubleHashV1,
        );
    }

    /**
     * @return list<int>
     */
    private function targetValues(
        PreparedCoordinatedWrite $prepared,
    ): array {
        return array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $prepared->lease()->targets()->versions(),
        );
    }
}
