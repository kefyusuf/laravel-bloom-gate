<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/Application/Task14MembershipFixtures.php';

use Kefyusuf\BloomGate\Application\ConsistencyContractViolation;
use Kefyusuf\BloomGate\Application\MembershipAdder;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Tests\Support\Application\Task14Fixture;
use Kefyusuf\BloomGate\Tests\Support\Application\Task14GenerationContractStore;

use function Kefyusuf\BloomGate\Tests\Support\Application\task14Fixture;

function task14Adder(Task14Fixture $fixture): MembershipAdder
{
    return new MembershipAdder(
        registry: $fixture->registry,
        snapshots: $fixture->snapshots,
        contracts: $fixture->contracts,
        driver: $fixture->driver,
        probes: new BloomProbeGenerator,
        fingerprints: new SemanticFingerprintCalculator,
    );
}

it('treats add as a one-item managed bulk write using the active generation layout', function (): void {
    $layout = BloomLayout::create(
        128,
        4,
        ProbeAlgorithm::Sha256DoubleHashV1,
    );
    $fixture = task14Fixture(layout: $layout);

    task14Adder($fixture)->add(
        'users.email',
        'User@Example.test',
    );

    expect($fixture->normalizer->calls)->toBe(1)
        ->and($fixture->normalizer->values)->toBe(['User@Example.test'])
        ->and($fixture->driver->addCalls)->toBe(0)
        ->and($fixture->driver->addManyCalls)->toBe(1)
        ->and($fixture->driver->items)->toHaveCount(1)
        ->and($fixture->driver->items[0]->layout()->equals($layout))->toBeTrue()
        ->and($fixture->driver->name?->equals($fixture->name))->toBeTrue()
        ->and($fixture->driver->version?->value())->toBe(4);
});

it('normalizes every addMany value once and uses one bounded bulk driver call', function (): void {
    $fixture = task14Fixture();

    task14Adder($fixture)->addMany(
        'users.email',
        ['A', 42, 'C'],
    );

    expect($fixture->normalizer->calls)->toBe(3)
        ->and($fixture->normalizer->values)->toBe(['A', 42, 'C'])
        ->and($fixture->driver->addCalls)->toBe(0)
        ->and($fixture->driver->addManyCalls)->toBe(1)
        ->and($fixture->driver->items)->toHaveCount(3);

    foreach ($fixture->driver->items as $positions) {
        expect($positions->layout()->equals($fixture->layout))->toBeTrue();
    }
});

it('is a successful no-op when no active generation exists', function (): void {
    $fixture = task14Fixture(withActiveSnapshot: false);

    task14Adder($fixture)->addMany(
        'users.email',
        ['A', 'B'],
    );

    expect($fixture->registry->getCalls)->toBe(1)
        ->and($fixture->normalizer->calls)->toBe(0)
        ->and($fixture->contracts->readCalls)->toBe(0)
        ->and($fixture->driver->addManyCalls)->toBe(0);
});

it('synchronizes active pre-add generation even when query optimization is disabled', function (
    bool $globalEnabled,
    bool $filterEnabled,
): void {
    $fixture = task14Fixture(
        globalEnabled: $globalEnabled,
        filterEnabled: $filterEnabled,
    );

    task14Adder($fixture)->add(
        'users.email',
        'value',
    );

    expect($fixture->registry->globalChecks)->toBe(0)
        ->and($fixture->driver->addManyCalls)->toBe(1);
})->with([
    'global disabled' => [false, true],
    'filter disabled' => [true, false],
]);

it('synchronizes an active generation even when health is not healthy without changing health', function (): void {
    $fixture = task14Fixture(health: HealthState::Stale);

    task14Adder($fixture)->add(
        'users.email',
        'value',
    );

    expect($fixture->driver->addManyCalls)->toBe(1)
        ->and($fixture->snapshots->calls)->toBe(1);
});

it('rejects writes for an active immutable generation', function (): void {
    $fixture = task14Fixture(consistency: ConsistencyContract::ImmutableV1);

    expect(fn () => task14Adder($fixture)->add(
        'users.email',
        'value',
    ))->toThrow(ConsistencyContractViolation::class)
        ->and($fixture->normalizer->calls)->toBe(0)
        ->and($fixture->driver->addManyCalls)->toBe(0);
});

it('rejects semantic mismatch as hard configuration failure before bloom mutation', function (
    GenerationSemanticContract $persisted,
): void {
    $fixture = task14Fixture(persistedContract: $persisted);

    expect(fn () => task14Adder($fixture)->add(
        'users.email',
        'value',
    ))->toThrow(InvalidConfiguration::class)
        ->and($fixture->driver->addManyCalls)->toBe(0);
})->with([
    'normalization mismatch' => new GenerationSemanticContract(
        NormalizationFingerprint::fromString('sha256:'.str_repeat('d', 64)),
        AuthoritativeSetFingerprint::fromString('sha256:'.str_repeat('b', 64)),
        ConsistencyFingerprint::fromString('sha256:'.str_repeat('c', 64)),
    ),
    'authoritative mismatch' => new GenerationSemanticContract(
        NormalizationFingerprint::fromString('sha256:'.str_repeat('a', 64)),
        AuthoritativeSetFingerprint::fromString('sha256:'.str_repeat('d', 64)),
        ConsistencyFingerprint::fromString('sha256:'.str_repeat('c', 64)),
    ),
    'consistency mismatch' => new GenerationSemanticContract(
        NormalizationFingerprint::fromString('sha256:'.str_repeat('a', 64)),
        AuthoritativeSetFingerprint::fromString('sha256:'.str_repeat('b', 64)),
        ConsistencyFingerprint::fromString('sha256:'.str_repeat('d', 64)),
    ),
]);

it('rejects an unbound active generation rather than silently skipping synchronization', function (): void {
    $fixture = task14Fixture();
    $contracts = new Task14GenerationContractStore(
        descriptor: null,
    );
    $adder = new MembershipAdder(
        registry: $fixture->registry,
        snapshots: $fixture->snapshots,
        contracts: $contracts,
        driver: $fixture->driver,
        probes: new BloomProbeGenerator,
        fingerprints: new SemanticFingerprintCalculator,
    );

    expect(fn () => $adder->add(
        'users.email',
        'value',
    ))->toThrow(InvalidConfiguration::class)
        ->and($fixture->driver->addManyCalls)->toBe(0);
});

it('propagates normalizer registry snapshot contract and driver failures', function (
    string $failurePoint,
): void {
    $failure = new RuntimeException($failurePoint.' failure');

    $fixture = match ($failurePoint) {
        'normalizer' => task14Fixture(normalizerFailure: $failure),
        'registry' => task14Fixture(registryFailure: $failure),
        'snapshot' => task14Fixture(snapshotFailure: $failure),
        'contract' => task14Fixture(contractFailure: $failure),
        'driver' => task14Fixture(driverFailure: $failure),
        default => throw new LogicException('Unexpected Task 14 failure point.'),
    };

    expect(fn () => task14Adder($fixture)->add(
        'users.email',
        'value',
    ))->toThrow(RuntimeException::class, $failurePoint.' failure');
})->with([
    'normalizer',
    'registry',
    'snapshot',
    'contract',
    'driver',
]);

it('does not dual-write candidates or own application database transactions', function (): void {
    $source = file_get_contents(
        __DIR__.'/../../../src/Application/MembershipAdder.php',
    );

    if ($source === false) {
        throw new RuntimeException('Unable to read MembershipAdder source.');
    }

    foreach ([
        'DB::',
        'transaction(',
        'Candidate',
        'candidate',
        'Eloquent',
        'Illuminate\\',
        'BloomDriver::add(',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});
