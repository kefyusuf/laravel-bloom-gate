<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kefyusuf\BloomGate\Application\OptimalBloomSizingV1;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Lifecycle\CandidateAllocator;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task17FilterDefinition;

function task17Configure(
    Task17FilterDefinition $definition,
    bool $enabled = true,
): void {
    config()->set('bloom-gate.default', 'memory');
    config()->set('bloom-gate.enabled', true);
    config()->set('bloom-gate.filters', [
        'users.email' => [
            'enabled' => $enabled,
            'definition' => Task17FilterDefinition::class,
            'capacity' => 1_000,
            'false_positive_rate' => 0.01,
        ],
    ]);

    app()->instance(Task17FilterDefinition::class, $definition);
}

function task17State(): ?FilterControlState
{
    return app(FilterControlStore::class)->read(
        FilterName::fromString('users.email'),
    );
}

function task17Generation(
    FilterControlState $state,
    int $version,
): GenerationControlState {
    foreach ($state->generations() as $generation) {
        if ($generation->version()->value() === $version) {
            return $generation;
        }
    }

    throw new RuntimeException('Task 17 generation not found.');
}

function task17CreateEmptyShadowCandidate(
    Task17FilterDefinition $definition,
): FilterVersion {
    $name = FilterName::fromString('users.email');
    $registered = app(FilterRegistry::class)->get($name);
    $layout = app(OptimalBloomSizingV1::class)->layout(
        $registered->capacity(),
        $registered->falsePositiveRate(),
    );
    $allocated = app(CandidateAllocator::class)->allocate($name);
    $candidate = $allocated->candidateVersion();

    if ($candidate === null) {
        throw new RuntimeException('Expected Task 17 candidate allocation.');
    }

    app(GenerationLifecycleTransitioner::class)->transition(
        $name,
        $candidate,
        LifecycleState::Building,
    );
    app(BulkBloomDriver::class)->provision($name, $candidate, $layout);

    $fingerprints = app(SemanticFingerprintCalculator::class);
    app(GenerationContractStore::class)->bind(
        $name,
        $candidate,
        $layout,
        new GenerationSemanticContract(
            normalizationFingerprint: $fingerprints->normalization(
                $definition->normalizer->identity(),
            ),
            authoritativeSetFingerprint: $fingerprints->authoritativeSet(
                $definition->authoritativeSet->identity(),
            ),
            consistencyFingerprint: $fingerprints->consistency(
                $definition->consistency(),
            ),
        ),
    );
    app(GenerationHealthUpdater::class)->update(
        $name,
        $candidate,
        HealthState::Healthy,
    );
    app(GenerationLifecycleTransitioner::class)->transition(
        $name,
        $candidate,
        LifecycleState::Shadow,
    );

    return $candidate;
}

beforeEach(function (): void {
    config()->set('bloom-gate.default', 'memory');
    config()->set('bloom-gate.enabled', true);
    config()->set('bloom-gate.filters', []);
});

it('fails loudly when bloom build targets an unknown filter', function (): void {
    $exit = Artisan::call('bloom:build', ['filter' => 'missing.filter']);

    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('not registered');
});

it('builds only a candidate and reports layout plus processed count without leaking values', function (): void {
    $definition = new Task17FilterDefinition([
        'secret-one@example.test',
        'secret-two@example.test',
    ]);
    task17Configure($definition);

    $exit = Artisan::call('bloom:build', ['filter' => 'users.email']);
    $output = Artisan::output();
    $state = task17State();

    expect($exit)->toBe(0)
        ->and($state)->not->toBeNull()
        ->and($state?->activeVersion())->toBeNull()
        ->and($state?->candidateVersion()?->value())->toBe(1)
        ->and(task17Generation($state, 1)->lifecycle())->toBe(LifecycleState::Shadow)
        ->and(task17Generation($state, 1)->health())->toBe(HealthState::Healthy)
        ->and($output)->toContain('candidate=v1')
        ->and($output)->toContain('processed=2')
        ->and($output)->toContain('bits=')
        ->and($output)->toContain('hashes=')
        ->and($output)->toContain('Sha256DoubleHashV1')
        ->and($output)->not->toContain('secret-one@example.test')
        ->and($output)->not->toContain('secret-two@example.test');
});

it('leaves an interrupted build candidate observable through status', function (): void {
    $definition = new Task17FilterDefinition(['first', 'second']);
    $definition->authoritativeSet->throwAfter = 1;
    task17Configure($definition);

    $exit = Artisan::call('bloom:build', ['filter' => 'users.email']);

    expect($exit)->toBe(1);

    $state = task17State();

    expect($state)->not->toBeNull()
        ->and($state?->activeVersion())->toBeNull()
        ->and($state?->candidateVersion()?->value())->toBe(1)
        ->and(task17Generation($state, 1)->lifecycle())->toBe(LifecycleState::Building)
        ->and(task17Generation($state, 1)->health())->toBe(HealthState::Unavailable);

    $statusExit = Artisan::call('bloom:status', ['filter' => 'users.email']);
    $output = Artisan::output();

    expect($statusExit)->toBe(0)
        ->and($output)->toContain('candidate')
        ->and($output)->toContain('v1')
        ->and($output)->toContain('Building')
        ->and($output)->toContain('Unavailable');
});

it('verifies only a current shadow healthy candidate and reports evidence', function (): void {
    $definition = new Task17FilterDefinition(['one', 'two']);
    task17Configure($definition);

    expect(Artisan::call('bloom:verify', ['filter' => 'users.email']))->toBe(1);

    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::call('bloom:verify', ['filter' => 'users.email']))->toBe(0);

    $output = Artisan::output();
    $state = task17State();

    expect($state)->not->toBeNull()
        ->and($state?->activeVersion())->toBeNull()
        ->and($state?->candidateVersion()?->value())->toBe(1)
        ->and(task17Generation($state, 1)->lifecycle())->toBe(LifecycleState::Verified)
        ->and(task17Generation($state, 1)->health())->toBe(HealthState::Healthy)
        ->and($output)->toContain('status=Passed')
        ->and($output)->toContain('checked=2');
});

it('reports false-negative verification without promoting the candidate', function (): void {
    $definition = new Task17FilterDefinition(['one', 'two']);
    task17Configure($definition);
    task17CreateEmptyShadowCandidate($definition);

    $exit = Artisan::call('bloom:verify', ['filter' => 'users.email']);
    $output = Artisan::output();
    $state = task17State();

    expect($exit)->toBe(1)
        ->and($state)->not->toBeNull()
        ->and($state?->activeVersion())->toBeNull()
        ->and($state?->candidateVersion()?->value())->toBe(1)
        ->and(task17Generation($state, 1)->lifecycle())->toBe(LifecycleState::Shadow)
        ->and(task17Generation($state, 1)->health())->toBe(HealthState::Stale)
        ->and($output)->toContain('status=FalseNegativeDetected')
        ->and($output)->toContain('checked=1');
});

it('activates immutable candidates without a quiescent flag', function (): void {
    $definition = new Task17FilterDefinition(['one', 'two']);
    task17Configure($definition);

    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::call('bloom:activate', ['filter' => 'users.email']))->toBe(0);

    $state = task17State();

    expect($state)->not->toBeNull()
        ->and($state?->activeVersion()?->value())->toBe(1)
        ->and($state?->candidateVersion())->toBeNull()
        ->and(task17Generation($state, 1)->lifecycle())->toBe(LifecycleState::Active);
});

it('requires quiescent acknowledgement for preadd activation and reconciles before fresh verification', function (): void {
    $definition = new Task17FilterDefinition(
        ['one'],
        ConsistencyContract::PreAddV1,
    );
    task17Configure($definition);

    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0);

    $definition->authoritativeSet->values[] = 'late-member';

    expect(Artisan::call('bloom:activate', ['filter' => 'users.email']))->toBe(1);

    $blocked = task17State();

    expect($blocked?->activeVersion())->toBeNull()
        ->and($blocked?->candidateVersion()?->value())->toBe(1);

    expect(Artisan::call('bloom:activate', [
        'filter' => 'users.email',
        '--quiescent' => true,
    ]))->toBe(0);

    $activated = task17State();

    expect($activated?->activeVersion()?->value())->toBe(1)
        ->and($activated?->candidateVersion())->toBeNull()
        ->and(task17Generation($activated, 1)->lifecycle())->toBe(LifecycleState::Active);
});

it('keeps the existing active generation unchanged when fresh activation verification fails', function (): void {
    $definition = new Task17FilterDefinition(['one']);
    task17Configure($definition);

    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::call('bloom:activate', ['filter' => 'users.email']))->toBe(0);

    $definition->authoritativeSet->values[] = 'two';
    $candidate = task17CreateEmptyShadowCandidate($definition);

    expect($candidate->value())->toBe(2)
        ->and(Artisan::call('bloom:activate', ['filter' => 'users.email']))->toBe(1);

    $state = task17State();

    expect($state?->activeVersion()?->value())->toBe(1)
        ->and($state?->candidateVersion()?->value())->toBe(2)
        ->and(task17Generation($state, 1)->lifecycle())->toBe(LifecycleState::Active)
        ->and(task17Generation($state, 2)->health())->toBe(HealthState::Stale);
});

it('discards only the current candidate and leaves the active generation untouched', function (): void {
    $definition = new Task17FilterDefinition(['one']);
    task17Configure($definition);

    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::call('bloom:activate', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::call('bloom:discard', ['filter' => 'users.email']))->toBe(0);

    $state = task17State();

    expect($state?->activeVersion()?->value())->toBe(1)
        ->and($state?->candidateVersion())->toBeNull()
        ->and(task17Generation($state, 1)->lifecycle())->toBe(LifecycleState::Active)
        ->and(task17Generation($state, 2)->lifecycle())->toBe(LifecycleState::Retired);
});

it('reports registered lifecycle layout semantic binding and consistency status without membership values', function (): void {
    $definition = new Task17FilterDefinition([
        'private-a',
        'private-b',
    ]);
    task17Configure($definition, enabled: false);

    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::call('bloom:status', ['filter' => 'users.email']))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('users.email')
        ->and($output)->toContain('registered=yes')
        ->and($output)->toContain('enabled=no')
        ->and($output)->toContain('candidate')
        ->and($output)->toContain('v1')
        ->and($output)->toContain('Shadow')
        ->and($output)->toContain('Healthy')
        ->and($output)->toContain('bits=')
        ->and($output)->toContain('hashes=')
        ->and($output)->toContain('semantic-bound=yes')
        ->and($output)->toContain('semantic-match=yes')
        ->and($output)->toContain('ImmutableV1')
        ->and($output)->not->toContain('private-a')
        ->and($output)->not->toContain('private-b');

    $definition->normalizer->semanticIdentity = 'task17-normalizer@2';

    expect(Artisan::call('bloom:status', ['filter' => 'users.email']))->toBe(0);

    $mismatchOutput = Artisan::output();

    expect($mismatchOutput)->toContain('semantic-match=no');
});

it('lists configured filters when status is called without a filter argument', function (): void {
    $definition = new Task17FilterDefinition([]);
    task17Configure($definition);

    expect(Artisan::call('bloom:status'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('users.email');
});

it('keeps lifecycle command discovery lazy and side effect free', function (): void {
    app()->bind(
        'redis',
        static fn (): never => throw new RuntimeException(
            'Redis must not resolve while discovering Bloom Gate commands.',
        ),
    );
    config()->set('bloom-gate.default', 'redis');
    config()->set('bloom-gate.drivers.redis.connection', 'definitely-missing');

    $exit = Artisan::call('list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('bloom:build')
        ->and($output)->toContain('bloom:verify')
        ->and($output)->toContain('bloom:activate')
        ->and($output)->toContain('bloom:discard')
        ->and($output)->toContain('bloom:status');
});

it('keeps console commands as thin application adapters', function (): void {
    foreach ([
        'BuildCommand.php',
        'VerifyCommand.php',
        'ActivateCommand.php',
        'DiscardCommand.php',
        'StatusCommand.php',
    ] as $file) {
        $path = __DIR__.'/../../../src/Laravel/Console/'.$file;
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException('Unable to read Task 17 console adapter.');
        }

        expect($source)->not->toContain('Drivers\\')
            ->and($source)->not->toContain('BloomDriver')
            ->and($source)->not->toContain('FilterControlStore')
            ->and($source)->not->toContain('GenerationContractStore')
            ->and($source)->not->toContain('SemanticFingerprintCalculator');
    }
});
