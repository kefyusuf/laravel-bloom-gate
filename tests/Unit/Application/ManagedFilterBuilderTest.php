<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/Application/Task9BuildFixtures.php';

use Kefyusuf\BloomGate\Application\BloomSizingUnsupported;
use Kefyusuf\BloomGate\Application\ManagedFilterBuilder;
use Kefyusuf\BloomGate\Application\OptimalBloomSizingV1;
use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Lifecycle\CandidateAllocator;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9BuildEventLog;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9RecordingBulkBloomDriver;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9RecordingControlStore;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9RecordingGenerationContractStore;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9RecordingNormalizer;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StaticFilterRegistry;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StreamingAuthoritativeSet;

function task9ActiveOnlyState(): FilterControlState
{
    $name = FilterName::fromString('users.email');
    $active = FilterVersion::fromInt(1);

    return new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: $active,
        activeVersion: $active,
        candidateVersion: null,
        generations: [
            new GenerationControlState(
                version: $active,
                lifecycle: LifecycleState::Active,
                health: HealthState::Healthy,
            ),
        ],
    );
}

function task9ExistingCandidateState(): FilterControlState
{
    $name = FilterName::fromString('users.email');
    $active = FilterVersion::fromInt(1);
    $candidate = FilterVersion::fromInt(2);

    return new FilterControlState(
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
                lifecycle: LifecycleState::Configured,
                health: HealthState::Unavailable,
            ),
        ],
    );
}

/**
 * @param  list<string|int>  $values
 * @return array{
 *   builder: ManagedFilterBuilder,
 *   events: Task9BuildEventLog,
 *   control: Task9RecordingControlStore,
 *   normalizer: Task9RecordingNormalizer,
 *   definition: Task9FilterDefinition,
 *   registry: Task9StaticFilterRegistry,
 *   driver: Task9RecordingBulkBloomDriver,
 *   contracts: Task9RecordingGenerationContractStore
 * }
 */
function task9BuilderFixture(
    array $values,
    int $chunkSize = 2,
    ?FilterControlState $initial = null,
    int $capacity = 1_000,
    float $falsePositiveRate = 0.01,
    string|int|null $normalizerThrowsOn = null,
    ?int $failOnBatch = null,
): array {
    $events = new Task9BuildEventLog;
    $control = new Task9RecordingControlStore($initial);
    $normalizer = new Task9RecordingNormalizer(
        $events,
        $normalizerThrowsOn,
    );
    $authoritativeSet = new Task9StreamingAuthoritativeSet(
        $events,
        $values,
    );
    $definition = new Task9FilterDefinition(
        $events,
        $normalizer,
        $authoritativeSet,
    );
    $registered = new RegisteredFilter(
        name: FilterName::fromString('users.email'),
        definition: $definition,
        queryOptimizationEnabled: true,
        capacity: $capacity,
        falsePositiveRate: $falsePositiveRate,
    );
    $registry = new Task9StaticFilterRegistry($registered);
    $driver = new Task9RecordingBulkBloomDriver(
        $events,
        $control,
        $failOnBatch,
    );
    $contracts = new Task9RecordingGenerationContractStore(
        $events,
        $control,
    );
    $transitions = new GenerationLifecycleTransitioner(
        $control,
        new LifecycleTransitionPolicy,
    );

    return [
        'builder' => new ManagedFilterBuilder(
            registry: $registry,
            sizing: new OptimalBloomSizingV1,
            allocator: new CandidateAllocator($control),
            transitions: $transitions,
            health: new GenerationHealthUpdater($control),
            driver: $driver,
            generationContracts: $contracts,
            probes: new BloomProbeGenerator,
            fingerprints: new SemanticFingerprintCalculator,
            chunkSize: $chunkSize,
        ),
        'events' => $events,
        'control' => $control,
        'normalizer' => $normalizer,
        'definition' => $definition,
        'registry' => $registry,
        'driver' => $driver,
        'contracts' => $contracts,
    ];
}

function task9Generation(
    FilterControlState $state,
    int $version,
): GenerationControlState {
    foreach ($state->generations() as $generation) {
        if ($generation->version()->value() === $version) {
            return $generation;
        }
    }

    throw new RuntimeException(sprintf(
        'Task 9 generation [%d] not found.',
        $version,
    ));
}

it('builds the next candidate with ordered lifecycle semantic and streamed bulk work', function (): void {
    $fixture = task9BuilderFixture(
        values: ['a', 'b', 'c', 'd', 'e'],
        chunkSize: 2,
        initial: task9ActiveOnlyState(),
    );

    $next = $fixture['builder']->build(
        FilterName::fromString('users.email'),
    );

    expect($next->revision()->value())->toBe(5)
        ->and($next->activeVersion()?->value())->toBe(1)
        ->and($next->candidateVersion()?->value())->toBe(2)
        ->and(task9Generation($next, 1)->lifecycle())->toBe(LifecycleState::Active)
        ->and(task9Generation($next, 1)->health())->toBe(HealthState::Healthy)
        ->and(task9Generation($next, 2)->lifecycle())->toBe(LifecycleState::Shadow)
        ->and(task9Generation($next, 2)->health())->toBe(HealthState::Healthy)
        ->and($fixture['registry']->getCalls)->toBe(1)
        ->and($fixture['definition']->normalizerResolutions)->toBe(1)
        ->and($fixture['definition']->authoritativeSetResolutions)->toBe(1)
        ->and($fixture['normalizer']->calls)->toBe(5)
        ->and(array_map(
            static fn (array $batch): int => count($batch),
            $fixture['driver']->batches,
        ))->toBe([2, 2, 1]);

    $expectedLayout = (new OptimalBloomSizingV1)->layout(1_000, 0.01);

    expect($fixture['driver']->provisionedVersion?->value())->toBe(2)
        ->and($fixture['driver']->provisionedLayout?->equals($expectedLayout))->toBeTrue()
        ->and($fixture['contracts']->bindCalls)->toBe(1)
        ->and($fixture['contracts']->boundVersion?->value())->toBe(2)
        ->and($fixture['contracts']->boundLayout?->equals($expectedLayout))->toBeTrue();

    $expectedSemantic = new GenerationSemanticContract(
        normalizationFingerprint: (new SemanticFingerprintCalculator)->normalization(
            $fixture['definition']->normalizer()->identity(),
        ),
        authoritativeSetFingerprint: (new SemanticFingerprintCalculator)->authoritativeSet(
            $fixture['definition']->authoritativeSet()->identity(),
        ),
        consistencyFingerprint: (new SemanticFingerprintCalculator)->consistency(
            $fixture['definition']->consistency(),
        ),
    );

    expect($fixture['contracts']->boundContract?->equals($expectedSemantic))->toBeTrue();

    $events = $fixture['events']->events;
    $provision = array_search('provision:Building', $events, true);
    $bind = array_search('bind:Building', $events, true);
    $yieldTwo = array_search('values:yield:1', $events, true);
    $firstBulk = array_search('bulk:2', $events, true);
    $yieldThree = array_search('values:yield:2', $events, true);

    expect($provision)->not->toBeFalse()
        ->and($bind)->not->toBeFalse()
        ->and($yieldTwo)->not->toBeFalse()
        ->and($firstBulk)->not->toBeFalse()
        ->and($yieldThree)->not->toBeFalse();

    if (
        ! is_int($provision)
        || ! is_int($bind)
        || ! is_int($yieldTwo)
        || ! is_int($firstBulk)
        || ! is_int($yieldThree)
    ) {
        throw new RuntimeException('Expected Task 9 build events.');
    }

    expect($provision)->toBeLessThan($bind)
        ->and($bind)->toBeLessThan($yieldTwo)
        ->and($yieldTwo)->toBeLessThan($firstBulk)
        ->and($firstBulk)->toBeLessThan($yieldThree);

    $writes = $fixture['control']->writes();

    expect($writes)->toHaveCount(4)
        ->and(task9Generation($writes[0], 2)->lifecycle())->toBe(LifecycleState::Configured)
        ->and(task9Generation($writes[0], 2)->health())->toBe(HealthState::Unavailable)
        ->and(task9Generation($writes[1], 2)->lifecycle())->toBe(LifecycleState::Building)
        ->and(task9Generation($writes[1], 2)->health())->toBe(HealthState::Unavailable)
        ->and(task9Generation($writes[2], 2)->lifecycle())->toBe(LifecycleState::Building)
        ->and(task9Generation($writes[2], 2)->health())->toBe(HealthState::Healthy)
        ->and(task9Generation($writes[3], 2)->lifecycle())->toBe(LifecycleState::Shadow)
        ->and(task9Generation($writes[3], 2)->health())->toBe(HealthState::Healthy);
});

it('builds a valid empty managed candidate without a bulk write', function (): void {
    $fixture = task9BuilderFixture(
        values: [],
        initial: task9ActiveOnlyState(),
    );

    $next = $fixture['builder']->build(
        FilterName::fromString('users.email'),
    );

    expect($fixture['driver']->batches)->toBe([])
        ->and($fixture['contracts']->bindCalls)->toBe(1)
        ->and(task9Generation($next, 2)->lifecycle())->toBe(LifecycleState::Shadow)
        ->and(task9Generation($next, 2)->health())->toBe(HealthState::Healthy)
        ->and($next->activeVersion()?->value())->toBe(1);
});

it('refuses a new build when a candidate already exists before touching data plane', function (): void {
    $fixture = task9BuilderFixture(
        values: ['a'],
        initial: task9ExistingCandidateState(),
    );

    expect(fn () => $fixture['builder']->build(
        FilterName::fromString('users.email'),
    ))->toThrow(InvalidArgumentException::class);

    expect($fixture['driver']->provisionedLayout)->toBeNull()
        ->and($fixture['contracts']->bindCalls)->toBe(0)
        ->and($fixture['driver']->batches)->toBe([])
        ->and($fixture['control']->writes())->toBe([]);
});

it('fails sizing before candidate allocation or data-plane mutation', function (): void {
    $fixture = task9BuilderFixture(
        values: ['a'],
        initial: task9ActiveOnlyState(),
        capacity: 150_000_000,
        falsePositiveRate: 0.001,
    );

    expect(fn () => $fixture['builder']->build(
        FilterName::fromString('users.email'),
    ))->toThrow(BloomSizingUnsupported::class);

    expect($fixture['control']->writes())->toBe([])
        ->and($fixture['driver']->provisionedLayout)->toBeNull()
        ->and($fixture['contracts']->bindCalls)->toBe(0)
        ->and($fixture['events']->events)->not->toContain('values:start');
});

it('leaves a failed operational build visible building and non active without recovery', function (): void {
    $fixture = task9BuilderFixture(
        values: ['a', 'b', 'c'],
        chunkSize: 2,
        initial: task9ActiveOnlyState(),
        failOnBatch: 1,
    );

    expect(fn () => $fixture['builder']->build(
        FilterName::fromString('users.email'),
    ))->toThrow(BloomDriverOperationFailed::class);

    $persisted = $fixture['control']->read(
        FilterName::fromString('users.email'),
    );

    if ($persisted === null) {
        throw new RuntimeException('Expected failed candidate to remain visible.');
    }

    expect($persisted->activeVersion()?->value())->toBe(1)
        ->and($persisted->candidateVersion()?->value())->toBe(2)
        ->and(task9Generation($persisted, 1)->lifecycle())->toBe(LifecycleState::Active)
        ->and(task9Generation($persisted, 2)->lifecycle())->toBe(LifecycleState::Building)
        ->and(task9Generation($persisted, 2)->health())->toBe(HealthState::Unavailable);
});

it('propagates normalizer programming failure and does not silently retire candidate', function (): void {
    $fixture = task9BuilderFixture(
        values: ['a', 'explode', 'c'],
        initial: task9ActiveOnlyState(),
        normalizerThrowsOn: 'explode',
    );

    expect(fn () => $fixture['builder']->build(
        FilterName::fromString('users.email'),
    ))->toThrow(RuntimeException::class, 'Task 9 normalizer failure.');

    $persisted = $fixture['control']->read(
        FilterName::fromString('users.email'),
    );

    if ($persisted === null) {
        throw new RuntimeException('Expected failed candidate to remain visible.');
    }

    expect($persisted->activeVersion()?->value())->toBe(1)
        ->and($persisted->candidateVersion()?->value())->toBe(2)
        ->and(task9Generation($persisted, 2)->lifecycle())->toBe(LifecycleState::Building)
        ->and(task9Generation($persisted, 2)->health())->toBe(HealthState::Unavailable);
});

it('rejects non positive build chunk sizes', function (int $chunkSize): void {
    expect(fn (): array => task9BuilderFixture(
        values: [],
        chunkSize: $chunkSize,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => 0,
    'negative' => -1,
]);
