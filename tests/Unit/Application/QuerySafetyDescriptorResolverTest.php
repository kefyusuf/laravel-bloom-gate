<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\Exception\BloomFilterNotProvisioned;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\GenerationContractStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;

final class Task11SnapshotReader implements ActiveGenerationSnapshotReader
{
    public function __construct(
        private ?ActiveGenerationSnapshot $snapshot = null,
        private ?Throwable $failure = null,
    ) {}

    public function readActive(FilterName $name): ?ActiveGenerationSnapshot
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->snapshot;
    }
}

final class Task11GenerationContractStore implements GenerationContractStore
{
    public function __construct(
        private ?ManagedGenerationDescriptor $descriptor = null,
        private ?Throwable $failure = null,
    ) {}

    public function read(
        FilterName $name,
        FilterVersion $version,
    ): ?ManagedGenerationDescriptor {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->descriptor;
    }

    public function bind(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $expectedLayout,
        GenerationSemanticContract $semanticContract,
    ): void {
        throw new LogicException('Task 11 resolver tests do not bind generation contracts.');
    }
}

function task11Name(): FilterName
{
    return FilterName::fromString('users.email');
}

function task11Layout(int $bits = 32): BloomLayout
{
    return BloomLayout::create(
        $bits,
        3,
        ProbeAlgorithm::Sha256DoubleHashV1,
    );
}

function task11Contract(
    string $normalization = 'a',
    string $authoritative = 'b',
    string $consistency = 'c',
): GenerationSemanticContract {
    return new GenerationSemanticContract(
        NormalizationFingerprint::fromString('sha256:'.str_repeat($normalization, 64)),
        AuthoritativeSetFingerprint::fromString('sha256:'.str_repeat($authoritative, 64)),
        ConsistencyFingerprint::fromString('sha256:'.str_repeat($consistency, 64)),
    );
}

function task11Snapshot(
    LifecycleState $lifecycle = LifecycleState::Active,
    HealthState $health = HealthState::Healthy,
    int $revision = 7,
    int $version = 2,
): ActiveGenerationSnapshot {
    return new ActiveGenerationSnapshot(
        task11Name(),
        FilterStateRevision::fromInt($revision),
        FilterVersion::fromInt($version),
        $lifecycle,
        $health,
    );
}

function task11Resolver(
    ?ActiveGenerationSnapshot $snapshot,
    ?ManagedGenerationDescriptor $descriptor,
): QuerySafetyDescriptorResolver {
    return new QuerySafetyDescriptorResolver(
        new Task11SnapshotReader($snapshot),
        new Task11GenerationContractStore($descriptor),
    );
}

it('fails open when there is no eligible active snapshot', function (): void {
    $resolution = task11Resolver(null, null)->resolve(
        task11Name(),
        task11Contract(),
    );

    expect($resolution->descriptor())->toBeNull()
        ->and($resolution->bypassReason()?->code())->toBe('active_version_unavailable');
});

it('fails open for non-active or non-healthy active state', function (
    LifecycleState $lifecycle,
    HealthState $health,
    string $reason,
): void {
    $resolution = task11Resolver(
        task11Snapshot($lifecycle, $health),
        null,
    )->resolve(
        task11Name(),
        task11Contract(),
    );

    expect($resolution->descriptor())->toBeNull()
        ->and($resolution->bypassReason()?->code())->toBe($reason);
})->with([
    'lifecycle' => [
        LifecycleState::Shadow,
        HealthState::Healthy,
        'lifecycle_not_active',
    ],
    'health' => [
        LifecycleState::Active,
        HealthState::Stale,
        'health_not_healthy',
    ],
]);

it('fails open when managed semantic binding is missing', function (): void {
    $resolution = task11Resolver(
        task11Snapshot(),
        null,
    )->resolve(
        task11Name(),
        task11Contract(),
    );

    expect($resolution->descriptor())->toBeNull()
        ->and($resolution->bypassReason()?->code())->toBe('generation_contract_unbound');
});

it('returns exact generation layout and pinned control identity when semantics match', function (): void {
    $layout = task11Layout(64);
    $expected = task11Contract();
    $resolution = task11Resolver(
        task11Snapshot(revision: 11, version: 4),
        new ManagedGenerationDescriptor($layout, $expected),
    )->resolve(
        task11Name(),
        $expected,
    );
    $descriptor = $resolution->descriptor();

    expect($resolution->bypassReason())->toBeNull()
        ->and($descriptor)->not->toBeNull()
        ->and($descriptor?->filterName()->equals(task11Name()))->toBeTrue()
        ->and($descriptor?->revision()->value())->toBe(11)
        ->and($descriptor?->activeVersion()->value())->toBe(4)
        ->and($descriptor?->layout()->equals($layout))->toBeTrue()
        ->and($descriptor?->semanticContract()->equals($expected))->toBeTrue();
});

it('fails open with distinct stable reasons for every semantic mismatch', function (
    GenerationSemanticContract $persisted,
    string $reason,
): void {
    $expected = task11Contract();
    $resolution = task11Resolver(
        task11Snapshot(),
        new ManagedGenerationDescriptor(task11Layout(), $persisted),
    )->resolve(task11Name(), $expected);

    expect($resolution->descriptor())->toBeNull()
        ->and($resolution->bypassReason()?->code())->toBe($reason);
})->with([
    'normalization' => [
        task11Contract('d', 'b', 'c'),
        'normalization_mismatch',
    ],
    'authoritative set' => [
        task11Contract('a', 'd', 'c'),
        'authoritative_set_mismatch',
    ],
    'consistency' => [
        task11Contract('a', 'b', 'd'),
        'consistency_mismatch',
    ],
]);

it('maps known operational preparation failures to bypass and propagates programming errors', function (): void {
    $resolution = (new QuerySafetyDescriptorResolver(
        new Task11SnapshotReader(
            failure: new FilterControlStateCorrupt('bad state'),
        ),
        new Task11GenerationContractStore,
    ))->resolve(task11Name(), task11Contract());

    expect($resolution->descriptor())->toBeNull()
        ->and($resolution->bypassReason()?->code())->toBe('operation_failed');

    expect(fn () => (new QuerySafetyDescriptorResolver(
        new Task11SnapshotReader(task11Snapshot()),
        new Task11GenerationContractStore(
            failure: new InvalidArgumentException('programming failure'),
        ),
    ))->resolve(task11Name(), task11Contract()))
        ->toThrow(InvalidArgumentException::class, 'programming failure');
});

it('maps managed generation storage failures to stable bypass reasons', function (
    Throwable $failure,
    string $reason,
): void {
    $resolution = (new QuerySafetyDescriptorResolver(
        new Task11SnapshotReader(task11Snapshot()),
        new Task11GenerationContractStore(failure: $failure),
    ))->resolve(task11Name(), task11Contract());

    expect($resolution->descriptor())->toBeNull()
        ->and($resolution->bypassReason()?->code())->toBe($reason);
})->with([
    'missing' => [
        new BloomFilterNotProvisioned('missing'),
        'generation_storage_unavailable',
    ],
    'corrupt' => [
        new BloomStorageCorrupt('corrupt'),
        'generation_storage_corrupt',
    ],
    'backend' => [
        new GenerationContractStoreOperationFailed('backend'),
        'backend_unavailable',
    ],
]);
