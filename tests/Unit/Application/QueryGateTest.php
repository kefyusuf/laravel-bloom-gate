<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/Application/Task13QueryGateFixtures.php';

use Kefyusuf\BloomGate\Application\ExistenceResult;
use Kefyusuf\BloomGate\Application\QueryGate;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownFilter;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Tests\Support\Application\Task13Fixture;
use function Kefyusuf\BloomGate\Tests\Support\Application\task13Fixture;

function task13Gate(Task13Fixture $fixture): QueryGate
{
    return new QueryGate(
        registry: $fixture->registry,
        resolver: $fixture->resolver,
        authorizedProbe: $fixture->probe,
        probes: new BloomProbeGenerator,
        fingerprints: new SemanticFingerprintCalculator,
    );
}

it('returns a trusted negative without authoritative lookup and normalizes exactly once', function (): void {
    $fixture = task13Fixture(
        AuthorizedProbeResult::definitelyAbsent(),
        authoritativeResult: true,
        capacity: 7,
        falsePositiveRate: 0.9,
    );

    $result = task13Gate($fixture)->existsResult(
        'users.email',
        'User@Example.test',
    );

    expect($result)->toBeInstanceOf(ExistenceResult::class)
        ->and($result->exists())->toBeFalse()
        ->and($result->membership())->toBe(Membership::DefinitelyAbsent)
        ->and($result->bypassReason())->toBeNull()
        ->and($fixture->normalizer->calls)->toBe(1)
        ->and($fixture->normalizer->values)->toBe(['User@Example.test'])
        ->and($fixture->authoritativeSet->existsCalls)->toBe(0)
        ->and($fixture->probe->calls)->toBe(1)
        ->and($fixture->probe->positions)->not->toBeNull()
        ->and($fixture->probe->positions?->layout()->equals($fixture->layout))->toBeTrue()
        ->and($fixture->probe->descriptor?->layout()->equals($fixture->layout))->toBeTrue();
});

it('preserves authoritative truth for maybe-present results', function (
    bool $authoritativeExists,
): void {
    $fixture = task13Fixture(
        AuthorizedProbeResult::maybePresent(),
        authoritativeResult: $authoritativeExists,
    );

    $result = task13Gate($fixture)->existsResult(
        'users.email',
        42,
    );

    expect($result->exists())->toBe($authoritativeExists)
        ->and($result->membership())->toBe(Membership::MaybePresent)
        ->and($result->bypassReason())->toBeNull()
        ->and($fixture->normalizer->calls)->toBe(1)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($fixture->authoritativeSet->seenBytes)->toBe(['normalized:42']);
})->with([
    'authoritative true' => true,
    'authoritative false' => false,
]);

it('preserves authoritative truth and reason for authorized-probe bypass', function (
    bool $authoritativeExists,
): void {
    $fixture = task13Fixture(
        AuthorizedProbeResult::bypassed(
            BypassReason::backendProfileUnasserted(),
        ),
        authoritativeResult: $authoritativeExists,
    );

    $result = task13Gate($fixture)->existsResult(
        'users.email',
        'value',
    );

    expect($result->exists())->toBe($authoritativeExists)
        ->and($result->membership())->toBe(Membership::Bypassed)
        ->and($result->bypassReason()?->code())->toBe('backend_profile_unasserted')
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($fixture->probe->calls)->toBe(1);
})->with([
    'authoritative true' => true,
    'authoritative false' => false,
]);

it('fails open before query-safety resolution when optimization is disabled', function (
    bool $globalEnabled,
    bool $filterEnabled,
): void {
    $fixture = task13Fixture(
        AuthorizedProbeResult::definitelyAbsent(),
        authoritativeResult: true,
        globalEnabled: $globalEnabled,
        filterEnabled: $filterEnabled,
    );

    $result = task13Gate($fixture)->existsResult(
        'users.email',
        'value',
    );

    expect($result->exists())->toBeTrue()
        ->and($result->membership())->toBe(Membership::Bypassed)
        ->and($result->bypassReason()?->code())->toBe('optimization_disabled')
        ->and($fixture->normalizer->calls)->toBe(1)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($fixture->snapshots->calls)->toBe(0)
        ->and($fixture->contracts->readCalls)->toBe(0)
        ->and($fixture->probe->calls)->toBe(0);
})->with([
    'global disabled' => [false, true],
    'filter disabled' => [true, false],
]);

it('falls back authoritatively when descriptor preparation bypasses', function (): void {
    $fixture = task13Fixture(
        AuthorizedProbeResult::definitelyAbsent(),
        authoritativeResult: false,
        withActiveSnapshot: false,
    );

    $result = task13Gate($fixture)->existsResult(
        'users.email',
        'value',
    );

    expect($result->exists())->toBeFalse()
        ->and($result->membership())->toBe(Membership::Bypassed)
        ->and($result->bypassReason()?->code())->toBe('active_version_unavailable')
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1)
        ->and($fixture->probe->calls)->toBe(0);
});

it('exists returns the authoritative-correct boolean semantics of existsResult', function (): void {
    $negative = task13Fixture(
        AuthorizedProbeResult::definitelyAbsent(),
        authoritativeResult: true,
    );
    $maybe = task13Fixture(
        AuthorizedProbeResult::maybePresent(),
        authoritativeResult: true,
    );

    expect(task13Gate($negative)->exists('users.email', 'value'))->toBeFalse()
        ->and(task13Gate($maybe)->exists('users.email', 'value'))->toBeTrue();
});

it('propagates authoritative lookup failures instead of inventing a boolean', function (): void {
    $failure = new RuntimeException('authoritative failure');
    $fixture = task13Fixture(
        AuthorizedProbeResult::maybePresent(),
        authoritativeFailure: $failure,
    );

    expect(fn () => task13Gate($fixture)->existsResult(
        'users.email',
        'value',
    ))->toThrow(RuntimeException::class, 'authoritative failure')
        ->and($fixture->authoritativeSet->existsCalls)->toBe(1);
});

it('propagates normalizer programming failures without probing or fallback', function (): void {
    $failure = new LogicException('normalizer programming failure');
    $fixture = task13Fixture(
        AuthorizedProbeResult::maybePresent(),
        normalizerFailure: $failure,
    );

    expect(fn () => task13Gate($fixture)->existsResult(
        'users.email',
        'value',
    ))->toThrow(LogicException::class, 'normalizer programming failure')
        ->and($fixture->probe->calls)->toBe(0)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(0);
});

it('propagates unexpected authorized-probe failures instead of converting them to bypass', function (): void {
    $failure = new RuntimeException('unexpected probe failure');
    $fixture = task13Fixture(
        AuthorizedProbeResult::maybePresent(),
        probeFailure: $failure,
    );

    expect(fn () => task13Gate($fixture)->existsResult(
        'users.email',
        'value',
    ))->toThrow(RuntimeException::class, 'unexpected probe failure')
        ->and($fixture->authoritativeSet->existsCalls)->toBe(0);
});

it('propagates unknown-filter and invalid-configuration failures rather than bypassing', function (
    Throwable $failure,
): void {
    $fixture = task13Fixture(
        AuthorizedProbeResult::maybePresent(),
        registryFailure: $failure,
    );

    expect(fn () => task13Gate($fixture)->existsResult(
        'users.email',
        'value',
    ))->toThrow($failure::class, $failure->getMessage())
        ->and($fixture->normalizer->calls)->toBe(0)
        ->and($fixture->authoritativeSet->existsCalls)->toBe(0)
        ->and($fixture->probe->calls)->toBe(0);
})->with([
    'unknown filter' => new UnknownFilter('unknown filter'),
    'invalid configuration' => new InvalidConfiguration('invalid configuration'),
]);
