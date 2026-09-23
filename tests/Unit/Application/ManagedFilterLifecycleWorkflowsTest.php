<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/Application/Task9BuildFixtures.php';

use Kefyusuf\BloomGate\Application\CandidateDiscarder;
use Kefyusuf\BloomGate\Application\ManagedFilterActivator;
use Kefyusuf\BloomGate\Application\ManagedFilterVerifier;
use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryGenerationContractStore;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationEvidenceApplier;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;
use Kefyusuf\BloomGate\Tests\Support\Application\Task10ControllableBloomDriver;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9BuildEventLog;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9RecordingNormalizer;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StaticFilterRegistry;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StreamingAuthoritativeSet;

/**
 * @return array{
 *     name: FilterName,
 *     candidate: FilterVersion,
 *     active: FilterVersion,
 *     layout: BloomLayout,
 *     values: list<string|int>,
 *     events: Task9BuildEventLog,
 *     normalizer: Task9RecordingNormalizer,
 *     set: Task9StreamingAuthoritativeSet,
 *     definition: Task9FilterDefinition,
 *     registry: Task9StaticFilterRegistry,
 *     control: MemoryFilterControlStore,
 *     innerDriver: MemoryBloomDriver,
 *     driver: Task10ControllableBloomDriver,
 *     contracts: MemoryGenerationContractStore,
 *     verifier: ManagedFilterVerifier,
 *     activator: ManagedFilterActivator,
 *     discard: CandidateDiscarder
 * }
 */
function task10Environment(
    ConsistencyContract $consistency = ConsistencyContract::ImmutableV1,
    LifecycleState $candidateLifecycle = LifecycleState::Shadow,
    HealthState $candidateHealth = HealthState::Healthy,
    bool $populateCandidate = true,
    bool $failAddMany = false,
    bool $failMightContain = false,
    bool $noopAddMany = false,
    bool $mismatchSemanticContract = false,
): array {
    $name = FilterName::fromString('users.email');
    $active = FilterVersion::fromInt(1);
    $candidate = FilterVersion::fromInt(2);
    $layout = BloomLayout::create(
        256,
        3,
        ProbeAlgorithm::Sha256DoubleHashV1,
    );
    $values = ['one@example.test', 'two@example.test', 'three@example.test'];

    $events = new Task9BuildEventLog;
    $normalizer = new Task9RecordingNormalizer($events);
    $set = new Task9StreamingAuthoritativeSet($events, $values);
    $definition = new Task9FilterDefinition(
        $events,
        $normalizer,
        $set,
        $consistency,
    );
    $registry = new Task9StaticFilterRegistry(
        new RegisteredFilter(
            name: $name,
            definition: $definition,
            queryOptimizationEnabled: true,
            capacity: 1_000,
            falsePositiveRate: 0.01,
        ),
    );

    $control = new MemoryFilterControlStore;
    $control->compareAndSwap(
        $name,
        new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(1),
            lastAllocatedVersion: $candidate,
            activeVersion: $active,
            candidateVersion: $candidate,
            generations: [
                new GenerationControlState(
                    version: $active,
                    lifecycle: LifecycleState::Active,
                    health: HealthState::Healthy,
                ),
                new GenerationControlState(
                    version: $candidate,
                    lifecycle: $candidateLifecycle,
                    health: $candidateHealth,
                ),
            ],
        ),
        null,
    );

    $innerDriver = new MemoryBloomDriver;
    $innerDriver->provision($name, $candidate, $layout);
    $contracts = new MemoryGenerationContractStore($innerDriver);
    $fingerprints = new SemanticFingerprintCalculator;
    $semanticContract = new GenerationSemanticContract(
        normalizationFingerprint: $fingerprints->normalization(
            $normalizer->identity(),
        ),
        authoritativeSetFingerprint: $fingerprints->authoritativeSet(
            $set->identity(),
        ),
        consistencyFingerprint: $fingerprints->consistency($consistency),
    );

    if ($mismatchSemanticContract) {
        $semanticContract = new GenerationSemanticContract(
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

    $contracts->bind(
        $name,
        $candidate,
        $layout,
        $semanticContract,
    );

    $probes = new BloomProbeGenerator;

    if ($populateCandidate) {
        $items = [];

        foreach ($values as $value) {
            $items[] = $probes->generate(
                NormalizedValue::fromBytes('normalized:'.(string) $value),
                $layout,
            );
        }

        $innerDriver->addMany($name, $candidate, $items);
    }

    $driver = new Task10ControllableBloomDriver(
        inner: $innerDriver,
        failAddMany: $failAddMany,
        failMightContain: $failMightContain,
        noopAddMany: $noopAddMany,
    );
    $health = new GenerationHealthUpdater($control);
    $transitions = new GenerationLifecycleTransitioner(
        $control,
        new LifecycleTransitionPolicy,
    );
    $verifier = new ManagedFilterVerifier(
        registry: $registry,
        control: $control,
        generationContracts: $contracts,
        activationVerifier: new ActivationVerifier($probes, $driver),
        evidenceApplier: new ActivationVerificationEvidenceApplier,
        health: $health,
        fingerprints: $fingerprints,
    );
    $activator = new ManagedFilterActivator(
        registry: $registry,
        control: $control,
        generationContracts: $contracts,
        driver: $driver,
        probes: $probes,
        fingerprints: $fingerprints,
        verifier: $verifier,
        promoter: new CandidatePromoter($control),
        chunkSize: 2,
    );
    $discard = new CandidateDiscarder(
        control: $control,
        transitions: $transitions,
    );

    return compact(
        'name',
        'candidate',
        'active',
        'layout',
        'values',
        'events',
        'normalizer',
        'set',
        'definition',
        'registry',
        'control',
        'innerDriver',
        'driver',
        'contracts',
        'verifier',
        'activator',
        'discard',
    );
}

it('verifies a fresh shadow healthy candidate and applies passed evidence', function (): void {
    $environment = task10Environment();

    $result = $environment['verifier']->verify($environment['name']);
    $state = $environment['control']->read($environment['name']);

    expect($result->status())->toBe(ActivationVerificationStatus::Passed)
        ->and($result->checkedCount())->toBe(3)
        ->and($state?->revision()->value())->toBe(2)
        ->and($state?->candidateVersion()?->value())->toBe(2)
        ->and($state?->generations()[1]->lifecycle())->toBe(LifecycleState::Verified)
        ->and($state?->generations()[1]->health())->toBe(HealthState::Healthy)
        ->and($environment['normalizer']->calls)->toBe(3)
        ->and(array_count_values($environment['events']->events)['values:start'] ?? 0)->toBe(1);
});

it('requires current shadow healthy state for normal managed verification', function (
    LifecycleState $lifecycle,
    HealthState $health,
): void {
    $environment = task10Environment(
        candidateLifecycle: $lifecycle,
        candidateHealth: $health,
    );

    expect(fn () => $environment['verifier']->verify($environment['name']))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'verified is activation-only fresh verification' => [
        LifecycleState::Verified,
        HealthState::Healthy,
    ],
    'shadow stale is not verifiable' => [
        LifecycleState::Shadow,
        HealthState::Stale,
    ],
]);

it('rejects runtime semantic drift before managed verification', function (): void {
    $environment = task10Environment(
        mismatchSemanticContract: true,
    );

    expect(fn () => $environment['verifier']->verify($environment['name']))
        ->toThrow(InvalidArgumentException::class);

    expect($environment['normalizer']->calls)->toBe(0)
        ->and($environment['driver']->mightContainCalls)->toBe(0);
});

it('marks the candidate stale when m4 verification detects a false negative', function (): void {
    $environment = task10Environment(
        populateCandidate: false,
    );

    $result = $environment['verifier']->verify($environment['name']);
    $state = $environment['control']->read($environment['name']);

    expect($result->status())->toBe(ActivationVerificationStatus::FalseNegativeDetected)
        ->and($result->checkedCount())->toBe(1)
        ->and($state?->generations()[1]->lifecycle())->toBe(LifecycleState::Shadow)
        ->and($state?->generations()[1]->health())->toBe(HealthState::Stale);
});

it('propagates typed bloom verification failures without manufacturing evidence', function (): void {
    $environment = task10Environment(
        failMightContain: true,
    );

    expect(fn () => $environment['verifier']->verify($environment['name']))
        ->toThrow(BloomDriverOperationFailed::class);

    $state = $environment['control']->read($environment['name']);

    expect($state?->revision()->value())->toBe(1)
        ->and($state?->generations()[1]->lifecycle())->toBe(LifecycleState::Shadow)
        ->and($state?->generations()[1]->health())->toBe(HealthState::Healthy);
});

it('activates immutable candidate with fresh verification and no reconciliation', function (): void {
    $environment = task10Environment(
        consistency: ConsistencyContract::ImmutableV1,
    );

    $next = $environment['activator']->activate($environment['name']);

    expect($next->activeVersion()?->value())->toBe(2)
        ->and($next->candidateVersion())->toBeNull()
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Retired)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Active)
        ->and($environment['driver']->addManyCalls)->toBe(0)
        ->and($environment['driver']->mightContainCalls)->toBe(3);
});

it('requires explicit quiescence before preadd activation mutates or verifies candidate', function (): void {
    $environment = task10Environment(
        consistency: ConsistencyContract::PreAddV1,
        populateCandidate: false,
    );

    expect(fn () => $environment['activator']->activate(
        $environment['name'],
    ))->toThrow(InvalidArgumentException::class);

    expect($environment['driver']->addManyCalls)->toBe(0)
        ->and($environment['driver']->mightContainCalls)->toBe(0)
        ->and($environment['control']->read($environment['name'])?->revision()->value())->toBe(1);
});

it('reconciles then freshly verifies and promotes preadd candidate under quiescence', function (): void {
    $environment = task10Environment(
        consistency: ConsistencyContract::PreAddV1,
        populateCandidate: false,
    );

    $next = $environment['activator']->activate(
        $environment['name'],
        quiescent: true,
    );

    expect($environment['driver']->addManyCalls)->toBe(2)
        ->and(array_map('count', $environment['driver']->batches))->toBe([2, 1])
        ->and($environment['driver']->mightContainCalls)->toBe(3)
        ->and($environment['normalizer']->calls)->toBe(6)
        ->and(array_count_values($environment['events']->events)['values:start'] ?? 0)->toBe(2)
        ->and($next->activeVersion()?->value())->toBe(2)
        ->and($next->candidateVersion())->toBeNull();
});

it('freshly reconciles and verifies an already verified preadd candidate without reapplying shadow evidence', function (): void {
    $environment = task10Environment(
        consistency: ConsistencyContract::PreAddV1,
        candidateLifecycle: LifecycleState::Verified,
        populateCandidate: false,
    );

    $next = $environment['activator']->activate(
        $environment['name'],
        quiescent: true,
    );

    expect($environment['driver']->addManyCalls)->toBe(2)
        ->and($environment['driver']->mightContainCalls)->toBe(3)
        ->and($next->activeVersion()?->value())->toBe(2)
        ->and($next->candidateVersion())->toBeNull()
        ->and($next->revision()->value())->toBe(2);
});

it('propagates reconciliation failure and blocks promotion', function (): void {
    $environment = task10Environment(
        consistency: ConsistencyContract::PreAddV1,
        populateCandidate: false,
        failAddMany: true,
    );

    expect(fn () => $environment['activator']->activate(
        $environment['name'],
        quiescent: true,
    ))->toThrow(BloomDriverOperationFailed::class);

    $state = $environment['control']->read($environment['name']);

    expect($state?->revision()->value())->toBe(1)
        ->and($state?->activeVersion()?->value())->toBe(1)
        ->and($state?->candidateVersion()?->value())->toBe(2)
        ->and($state?->generations()[1]->lifecycle())->toBe(LifecycleState::Shadow);
});

it('marks an already verified preadd candidate stale when fresh post-reconciliation verification fails', function (): void {
    $environment = task10Environment(
        consistency: ConsistencyContract::PreAddV1,
        candidateLifecycle: LifecycleState::Verified,
        populateCandidate: false,
        noopAddMany: true,
    );

    expect(fn () => $environment['activator']->activate(
        $environment['name'],
        quiescent: true,
    ))->toThrow(InvalidArgumentException::class);

    $state = $environment['control']->read($environment['name']);

    expect($state?->activeVersion()?->value())->toBe(1)
        ->and($state?->candidateVersion()?->value())->toBe(2)
        ->and($state?->generations()[1]->lifecycle())->toBe(LifecycleState::Verified)
        ->and($state?->generations()[1]->health())->toBe(HealthState::Stale);
});

it('discards only the current candidate through lifecycle retirement without data-plane destruction', function (): void {
    $environment = task10Environment();

    $next = $environment['discard']->discard($environment['name']);

    expect($next->activeVersion()?->value())->toBe(1)
        ->and($next->candidateVersion())->toBeNull()
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Active)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Retired)
        ->and($environment['driver']->destroyCalls)->toBe(0)
        ->and($environment['innerDriver']->layout(
            $environment['name'],
            $environment['candidate'],
        ))->not->toBeNull();
});

it('rejects discard when no current candidate exists', function (): void {
    $environment = task10Environment();
    $environment['discard']->discard($environment['name']);

    expect(fn () => $environment['discard']->discard($environment['name']))
        ->toThrow(InvalidArgumentException::class);
});

it('exposes no sampling verification API', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(ManagedFilterVerifier::class))
            ->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->not->toContain('sample')
        ->and($methods)->not->toContain('verifySample');
});
