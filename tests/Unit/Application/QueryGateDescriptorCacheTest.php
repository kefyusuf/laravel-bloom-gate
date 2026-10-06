<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/Application/Task13QueryGateFixtures.php';
require_once __DIR__.'/../../Support/Application/DescriptorCacheFixture.php';

use Kefyusuf\BloomGate\Application\QueryGate;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Tests\Support\Application\DescriptorCacheFixture;
use Kefyusuf\BloomGate\Tests\Support\Application\DescriptorCacheSequenceProbe;
use Kefyusuf\BloomGate\Tests\Support\Application\Task13Fixture;

use function Kefyusuf\BloomGate\Tests\Support\Application\task13Fixture;

function descriptorCacheGate(Task13Fixture $fixture, DescriptorCacheFixture $cache): QueryGate
{
    return new QueryGate($fixture->registry, $fixture->resolver, $fixture->probe,
        new BloomProbeGenerator, new SemanticFingerprintCalculator, descriptorCache: $cache);
}

it('reuses descriptors while normalizing and authorizing every lookup', function (): void {
    $fixture = task13Fixture(AuthorizedProbeResult::maybePresent(), authoritativeResult: true);
    $cache = new DescriptorCacheFixture;
    $gate = descriptorCacheGate($fixture, $cache);
    expect($gate->exists('users.email', 'first'))->toBeTrue();
    expect($gate->exists('users.email', 'second'))->toBeTrue();
    expect($fixture->snapshots->calls)->toBe(1)
        ->and($fixture->contracts->readCalls)->toBe(1)
        ->and($fixture->probe->calls)->toBe(2)
        ->and($fixture->normalizer->calls)->toBe(2)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(2)
        ->and($fixture->registry->getCalls)->toBe(2)
        ->and($cache->puts)->toBe(1);
});

it('refreshes cached drift once and falls back once when drift repeats', function (): void {
    $fixture = task13Fixture(AuthorizedProbeResult::definitelyAbsent(), authoritativeResult: true);
    $cache = new DescriptorCacheFixture;
    $gate = descriptorCacheGate($fixture, $cache);
    $gate->exists('users.email', 'warm');
    (new ReflectionProperty($fixture->probe, 'result'))->setValue($fixture->probe,
        AuthorizedProbeResult::bypassed(BypassReason::controlStateChanged()));
    $result = $gate->existsResult('users.email', 'changed');
    expect($result->exists())->toBeTrue()
        ->and($result->bypassReason()?->code())->toBe('control_state_changed')
        ->and($fixture->snapshots->calls)->toBe(2)
        ->and($fixture->probe->calls)->toBe(3)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($cache->descriptor)->toBeNull();
});

it('preserves fresh health bypass after cached control drift', function (): void {
    $fixture = task13Fixture(AuthorizedProbeResult::definitelyAbsent(), authoritativeResult: true);
    $cache = new DescriptorCacheFixture;
    $gate = descriptorCacheGate($fixture, $cache);
    $gate->exists('users.email', 'warm');
    (new ReflectionProperty($fixture->probe, 'result'))->setValue($fixture->probe,
        AuthorizedProbeResult::bypassed(BypassReason::controlStateChanged()));
    $old = $cache->descriptor;
    assert($old !== null);
    (new ReflectionProperty($fixture->snapshots, 'snapshot'))->setValue($fixture->snapshots,
        new ActiveGenerationSnapshot($fixture->name, $old->revision(),
            $old->activeVersion(), LifecycleState::Active,
            HealthState::Stale));
    $result = $gate->existsResult('users.email', 'stale');
    expect($result->bypassReason()?->code())->toBe('health_not_healthy')
        ->and($fixture->probe->calls)->toBe(2)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($cache->descriptor)->toBeNull();
});

it('does not retry cold drift or cached backend failures', function (bool $warm): void {
    $fixture = task13Fixture(AuthorizedProbeResult::definitelyAbsent(), authoritativeResult: true);
    $cache = new DescriptorCacheFixture;
    $gate = descriptorCacheGate($fixture, $cache);
    if ($warm) {
        $gate->exists('users.email', 'warm');
    }
    (new ReflectionProperty($fixture->probe, 'result'))->setValue($fixture->probe,
        AuthorizedProbeResult::bypassed($warm ? BypassReason::backendUnavailable() : BypassReason::controlStateChanged()));
    $gate->exists('users.email', 'value');
    expect($fixture->snapshots->calls)->toBe(1)
        ->and($fixture->probe->calls)->toBe($warm ? 2 : 1)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($cache->descriptor)->toBeNull();
})->with([false, true]);

it('contains cache failures without blocking resolution or authoritative fallback', function (string $operation): void {
    $fixture = task13Fixture(AuthorizedProbeResult::maybePresent(), authoritativeResult: true);
    $cache = new DescriptorCacheFixture;
    $cache->failure = $operation;
    if ($operation === 'forget') {
        (new ReflectionProperty($fixture->probe, 'result'))->setValue($fixture->probe,
            AuthorizedProbeResult::bypassed(BypassReason::backendUnavailable()));
    }
    expect(descriptorCacheGate($fixture, $cache)->exists('users.email', 'value'))->toBeTrue()
        ->and($fixture->snapshots->calls)->toBe(1)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1);
})->with(['get', 'put', 'forget']);

it('uses current semantics rather than a cached descriptor from an earlier definition', function (): void {
    $fixture = task13Fixture(AuthorizedProbeResult::definitelyAbsent(), authoritativeResult: true);
    $cache = new DescriptorCacheFixture;
    $gate = descriptorCacheGate($fixture, $cache);
    $gate->exists('users.email', 'warm');
    (new ReflectionProperty($fixture->definition, 'consistency'))->setValue($fixture->definition,
        ConsistencyContract::ImmutableV1);
    $result = $gate->existsResult('users.email', 'changed');
    expect($result->bypassReason()?->code())->toBe('consistency_mismatch')
        ->and($fixture->snapshots->calls)->toBe(2)
        ->and($fixture->probe->calls)->toBe(1)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($cache->descriptor)->toBeNull();
});

it('honors current optimization configuration without reusing cached eligibility', function (): void {
    $fixture = task13Fixture(AuthorizedProbeResult::definitelyAbsent(), authoritativeResult: true);
    $cache = new DescriptorCacheFixture;
    $gate = descriptorCacheGate($fixture, $cache);
    $gate->exists('users.email', 'warm');
    (new ReflectionProperty($fixture->registry, 'globalEnabled'))->setValue($fixture->registry, false);
    $result = $gate->existsResult('users.email', 'disabled');
    expect($result->bypassReason()?->code())->toBe('optimization_disabled')
        ->and($fixture->probe->calls)->toBe(1)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($cache->gets)->toBe(1);
});

it('recomputes positions against a freshly resolved layout after cached drift', function (): void {
    $fixture = task13Fixture(AuthorizedProbeResult::definitelyAbsent(), authoritativeResult: true);
    $cache = new DescriptorCacheFixture;
    descriptorCacheGate($fixture, $cache)->exists('users.email', 'warm');
    $cached = $cache->descriptor;
    assert($cached !== null);
    $layout = BloomLayout::create(128, 4,
        ProbeAlgorithm::Sha256DoubleHashV1);
    (new ReflectionProperty($fixture->contracts, 'descriptor'))->setValue($fixture->contracts,
        new ManagedGenerationDescriptor($layout, $cached->semanticContract()));
    $probe = new DescriptorCacheSequenceProbe([
        AuthorizedProbeResult::bypassed(BypassReason::controlStateChanged()),
        AuthorizedProbeResult::maybePresent(),
    ]);
    $gate = new QueryGate($fixture->registry, $fixture->resolver, $probe,
        new BloomProbeGenerator, new SemanticFingerprintCalculator, descriptorCache: $cache);
    expect($gate->exists('users.email', 'new-layout'))->toBeTrue()
        ->and($probe->positions[0]->layout()->equals($fixture->layout))->toBeTrue()
        ->and($probe->positions[1]->layout()->equals($layout))->toBeTrue()
        ->and($probe->positions[1]->count())->toBe(4)
        ->and($fixture->snapshots->calls)->toBe(2)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($fixture->normalizer->calls)->toBe(2)
        ->and($cache->descriptor->layout()->equals($layout))->toBeTrue();
});
