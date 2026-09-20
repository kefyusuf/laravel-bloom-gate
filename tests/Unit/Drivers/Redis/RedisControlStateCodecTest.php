<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;

function redisControlCodecState(): FilterControlState
{
    $name = FilterName::fromString('products.sku');
    $active = FilterVersion::fromInt(2);
    $candidate = FilterVersion::fromInt(5);

    return new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(9),
        lastAllocatedVersion: $candidate,
        activeVersion: $active,
        candidateVersion: $candidate,
        generations: [
            new GenerationControlState(
                version: $candidate,
                lifecycle: LifecycleState::Shadow,
                health: HealthState::Degraded,
            ),
            new GenerationControlState(
                version: FilterVersion::fromInt(1),
                lifecycle: LifecycleState::Retired,
                health: HealthState::Unavailable,
            ),
            new GenerationControlState(
                version: $active,
                lifecycle: LifecycleState::Active,
                health: HealthState::Healthy,
            ),
        ],
    );
}

/**
 * @return list<string>
 */
function redisControlCodecMinimalPayload(): array
{
    return [
        'format', 'control-v1',
        'revision', '1',
        'last_allocated_version', '1',
        'candidate_version', '1',
        'g:1:lifecycle', 'configured',
        'g:1:health', 'unavailable',
    ];
}

it('encodes control state deterministically with canonical control v1 tokens', function (): void {
    $encoded = (new RedisControlStateCodec)->encode(redisControlCodecState());

    expect($encoded)->toBe([
        'format', 'control-v1',
        'revision', '9',
        'last_allocated_version', '5',
        'active_version', '2',
        'candidate_version', '5',
        'g:1:lifecycle', 'retired',
        'g:1:health', 'unavailable',
        'g:2:lifecycle', 'active',
        'g:2:health', 'healthy',
        'g:5:lifecycle', 'shadow',
        'g:5:health', 'degraded',
    ]);
});

it('omits nullable pointer fields instead of encoding sentinel values', function (): void {
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(3);
    $state = new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt(4),
        lastAllocatedVersion: $version,
        activeVersion: null,
        candidateVersion: null,
        generations: [],
    );

    $encoded = (new RedisControlStateCodec)->encode($state);

    expect($encoded)->toBe([
        'format', 'control-v1',
        'revision', '4',
        'last_allocated_version', '3',
    ]);
    expect($encoded)->not->toContain('active_version');
    expect($encoded)->not->toContain('candidate_version');
});

it('decodes a valid payload regardless of redis hash field order', function (): void {
    $name = FilterName::fromString('products.sku');
    $payload = [
        'g:5:health', 'degraded',
        'candidate_version', '5',
        'format', 'control-v1',
        'g:2:lifecycle', 'active',
        'revision', '9',
        'g:1:health', 'unavailable',
        'active_version', '2',
        'g:5:lifecycle', 'shadow',
        'last_allocated_version', '5',
        'g:2:health', 'healthy',
        'g:1:lifecycle', 'retired',
    ];

    $decoded = (new RedisControlStateCodec)->decode($name, $payload);

    expect($decoded->filterName())->toBe($name)
        ->and($decoded->revision()->value())->toBe(9)
        ->and($decoded->lastAllocatedVersion()->value())->toBe(5)
        ->and($decoded->activeVersion()?->value())->toBe(2)
        ->and($decoded->candidateVersion()?->value())->toBe(5)
        ->and(array_map(
            static fn (GenerationControlState $generation): array => [
                $generation->version()->value(),
                $generation->lifecycle(),
                $generation->health(),
            ],
            $decoded->generations(),
        ))->toBe([
            [1, LifecycleState::Retired, HealthState::Unavailable],
            [2, LifecycleState::Active, HealthState::Healthy],
            [5, LifecycleState::Shadow, HealthState::Degraded],
        ]);
});

it('round trips every lifecycle token exactly', function (LifecycleState $lifecycle, string $token): void {
    $payload = [
        'format', 'control-v1',
        'revision', '1',
        'last_allocated_version', '1',
        'g:1:lifecycle', $token,
        'g:1:health', 'healthy',
    ];

    $decoded = (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    );

    expect($decoded->generations()[0]->lifecycle())->toBe($lifecycle);
})->with([
    'configured' => [LifecycleState::Configured, 'configured'],
    'building' => [LifecycleState::Building, 'building'],
    'shadow' => [LifecycleState::Shadow, 'shadow'],
    'verified' => [LifecycleState::Verified, 'verified'],
    'active' => [LifecycleState::Active, 'active'],
    'retired' => [LifecycleState::Retired, 'retired'],
]);

it('round trips every health token exactly', function (HealthState $health, string $token): void {
    $payload = [
        'format', 'control-v1',
        'revision', '1',
        'last_allocated_version', '1',
        'g:1:lifecycle', 'retired',
        'g:1:health', $token,
    ];

    $decoded = (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    );

    expect($decoded->generations()[0]->health())->toBe($health);
})->with([
    'healthy' => [HealthState::Healthy, 'healthy'],
    'degraded' => [HealthState::Degraded, 'degraded'],
    'stale' => [HealthState::Stale, 'stale'],
    'unavailable' => [HealthState::Unavailable, 'unavailable'],
]);

it('rejects unknown control formats', function (): void {
    $payload = redisControlCodecMinimalPayload();
    $payload[1] = 'control-v2';

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
});

it('rejects unknown top level fields instead of silently ignoring them', function (): void {
    $payload = [
        ...redisControlCodecMinimalPayload(),
        'ttl', '300',
    ];

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
});

it('rejects duplicate fields', function (): void {
    $payload = [
        ...redisControlCodecMinimalPayload(),
        'revision', '1',
    ];

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
});

it('rejects malformed generation field names', function (string $field): void {
    $payload = [
        'format', 'control-v1',
        'revision', '1',
        'last_allocated_version', '1',
        $field, 'configured',
        'g:1:health', 'unavailable',
    ];

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
})->with([
    'empty version' => 'g::lifecycle',
    'zero version' => 'g:0:lifecycle',
    'leading zero' => 'g:01:lifecycle',
    'negative version' => 'g:-1:lifecycle',
    'unknown generation property' => 'g:1:status',
    'extra segment' => 'g:1:lifecycle:extra',
]);

it('rejects incomplete lifecycle health generation pairs', function (string $missingField): void {
    $payload = redisControlCodecMinimalPayload();

    $filtered = [];
    for ($index = 0; $index < count($payload); $index += 2) {
        if ($payload[$index] === $missingField) {
            continue;
        }

        $filtered[] = $payload[$index];
        $filtered[] = $payload[$index + 1];
    }

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $filtered,
    ))->toThrow(FilterControlStateCorrupt::class);
})->with([
    'lifecycle missing' => 'g:1:lifecycle',
    'health missing' => 'g:1:health',
]);

it('rejects unknown lifecycle and health tokens', function (string $field, string $value): void {
    $payload = redisControlCodecMinimalPayload();

    $fieldIndex = array_search($field, $payload, true);
    if ($fieldIndex === false) {
        throw new RuntimeException('Expected codec test field.');
    }

    $payload[$fieldIndex + 1] = $value;

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
})->with([
    'unknown lifecycle' => ['g:1:lifecycle', 'CONFIGURED'],
    'unknown health' => ['g:1:health', 'HEALTHY'],
]);

it('rejects non canonical positive decimal values', function (string $field, string $value): void {
    $payload = redisControlCodecMinimalPayload();

    $fieldIndex = array_search($field, $payload, true);
    if ($fieldIndex === false) {
        throw new RuntimeException('Expected codec test field.');
    }

    $payload[$fieldIndex + 1] = $value;

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
})->with([
    'revision zero' => ['revision', '0'],
    'revision leading zero' => ['revision', '01'],
    'revision plus sign' => ['revision', '+1'],
    'last allocated leading zero' => ['last_allocated_version', '01'],
    'candidate leading zero' => ['candidate_version', '01'],
]);

it('rejects non canonical generation versions before semantic duplication is possible', function (): void {
    $payload = [
        'format', 'control-v1',
        'revision', '1',
        'last_allocated_version', '1',
        'g:1:lifecycle', 'retired',
        'g:1:health', 'unavailable',
        'g:01:lifecycle', 'retired',
        'g:01:health', 'unavailable',
    ];

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
});

it('rejects odd length or non string hash payloads', function (array $payload): void {
    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
})->with([
    'odd pair count' => [['format', 'control-v1', 'revision']],
    'non string field' => [[1, 'control-v1']],
    'non string value' => [['format', 1]],
]);

it('maps core invariant violations to control state corruption', function (): void {
    $payload = [
        'format', 'control-v1',
        'revision', '1',
        'last_allocated_version', '1',
        'active_version', '1',
        'g:1:lifecycle', 'shadow',
        'g:1:health', 'healthy',
    ];

    expect(fn () => (new RedisControlStateCodec)->decode(
        FilterName::fromString('products.sku'),
        $payload,
    ))->toThrow(FilterControlStateCorrupt::class);
});

it('does not encode ttl or expiration metadata', function (): void {
    $encoded = (new RedisControlStateCodec)->encode(redisControlCodecState());

    expect($encoded)->not->toContain('ttl');
    expect($encoded)->not->toContain('expires_at');
    expect($encoded)->not->toContain('expiration');
});
