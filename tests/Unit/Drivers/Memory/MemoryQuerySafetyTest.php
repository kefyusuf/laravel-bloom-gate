<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryAuthorizedProbe;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryGenerationContractStore;

final class Task11MismatchedSnapshotReader implements ActiveGenerationSnapshotReader
{
    public function __construct(
        private ActiveGenerationSnapshot $snapshot,
    ) {}

    public function readActive(FilterName $name): ActiveGenerationSnapshot
    {
        return $this->snapshot;
    }
}

function task11MemoryContract(): GenerationSemanticContract
{
    return new GenerationSemanticContract(
        NormalizationFingerprint::fromString('sha256:'.str_repeat('a', 64)),
        AuthoritativeSetFingerprint::fromString('sha256:'.str_repeat('b', 64)),
        ConsistencyFingerprint::fromString('sha256:'.str_repeat('c', 64)),
    );
}

/**
 * @return array{
 *   control: MemoryFilterControlStore,
 *   bloom: MemoryBloomDriver,
 *   contracts: MemoryGenerationContractStore,
 *   name: FilterName,
 *   version: FilterVersion,
 *   layout: BloomLayout,
 *   positions: BitPositions
 * }
 */
function task11MemoryFixture(): array
{
    $control = new MemoryFilterControlStore;
    $bloom = new MemoryBloomDriver;
    $contracts = new MemoryGenerationContractStore($bloom);
    $name = FilterName::fromString('users.email');
    $version = FilterVersion::fromInt(1);
    $layout = BloomLayout::create(32, 3, ProbeAlgorithm::Sha256DoubleHashV1);
    $positions = BitPositions::forLayout($layout, [1, 4, 7]);

    $bloom->provision($name, $version, $layout);
    $contracts->bind($name, $version, $layout, task11MemoryContract());

    $control->compareAndSwap(
        $name,
        new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt(1),
            lastAllocatedVersion: $version,
            activeVersion: $version,
            candidateVersion: null,
            generations: [
                new GenerationControlState(
                    $version,
                    LifecycleState::Active,
                    HealthState::Healthy,
                ),
            ],
        ),
        null,
    );

    return compact(
        'control',
        'bloom',
        'contracts',
        'name',
        'version',
        'layout',
        'positions',
    );
}

it('exposes active snapshot from the memory control store without full-store query coupling', function (): void {
    $fixture = task11MemoryFixture();

    expect($fixture['control'])->toBeInstanceOf(ActiveGenerationSnapshotReader::class);

    $snapshot = $fixture['control']->readActive($fixture['name']);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->revision()->value())->toBe(1)
        ->and($snapshot?->activeVersion()->value())->toBe(1)
        ->and($snapshot?->lifecycle())->toBe(LifecycleState::Active)
        ->and($snapshot?->health())->toBe(HealthState::Healthy);
});

it('returns definitely absent only after optimistic memory revalidation succeeds', function (): void {
    $fixture = task11MemoryFixture();
    $resolution = (new QuerySafetyDescriptorResolver(
        $fixture['control'],
        $fixture['contracts'],
    ))->resolve(
        $fixture['name'],
        task11MemoryContract(),
    );
    $descriptor = $resolution->descriptor();

    if ($descriptor === null) {
        throw new RuntimeException('Expected query safety descriptor.');
    }

    $result = (new MemoryAuthorizedProbe(
        $fixture['control'],
        $fixture['contracts'],
        $fixture['bloom'],
    ))->probe($descriptor, $fixture['positions']);

    expect($result->membership())->toBe(Membership::DefinitelyAbsent)
        ->and($result->bypassReason())->toBeNull();
});

it('returns maybe present after matching bits exist', function (): void {
    $fixture = task11MemoryFixture();
    $fixture['bloom']->add(
        $fixture['name'],
        $fixture['version'],
        $fixture['positions'],
    );
    $resolution = (new QuerySafetyDescriptorResolver(
        $fixture['control'],
        $fixture['contracts'],
    ))->resolve(
        $fixture['name'],
        task11MemoryContract(),
    );
    $descriptor = $resolution->descriptor();

    if ($descriptor === null) {
        throw new RuntimeException('Expected query safety descriptor.');
    }

    $result = (new MemoryAuthorizedProbe(
        $fixture['control'],
        $fixture['contracts'],
        $fixture['bloom'],
    ))->probe($descriptor, $fixture['positions']);

    expect($result->membership())->toBe(Membership::MaybePresent)
        ->and($result->bypassReason())->toBeNull();
});

it('bypasses when revalidation returns a snapshot for a different filter', function (): void {
    $fixture = task11MemoryFixture();
    $resolution = (new QuerySafetyDescriptorResolver(
        $fixture['control'],
        $fixture['contracts'],
    ))->resolve(
        $fixture['name'],
        task11MemoryContract(),
    );
    $descriptor = $resolution->descriptor();

    if ($descriptor === null) {
        throw new RuntimeException('Expected query safety descriptor.');
    }

    $current = $fixture['control']->readActive($fixture['name']);

    if ($current === null) {
        throw new RuntimeException('Expected active snapshot.');
    }

    $reader = new Task11MismatchedSnapshotReader(
        new ActiveGenerationSnapshot(
            FilterName::fromString('other.filter'),
            $current->revision(),
            $current->activeVersion(),
            $current->lifecycle(),
            $current->health(),
        ),
    );

    $result = (new MemoryAuthorizedProbe(
        $reader,
        $fixture['contracts'],
        $fixture['bloom'],
    ))->probe($descriptor, $fixture['positions']);

    expect($result->membership())->toBe(Membership::Bypassed)
        ->and($result->bypassReason()?->code())->toBe('control_state_changed');
});

it('bypasses when control revision changes after descriptor preparation', function (): void {
    $fixture = task11MemoryFixture();
    $resolution = (new QuerySafetyDescriptorResolver(
        $fixture['control'],
        $fixture['contracts'],
    ))->resolve(
        $fixture['name'],
        task11MemoryContract(),
    );
    $descriptor = $resolution->descriptor();

    if ($descriptor === null) {
        throw new RuntimeException('Expected query safety descriptor.');
    }

    $fixture['control']->compareAndSwap(
        $fixture['name'],
        new FilterControlState(
            filterName: $fixture['name'],
            revision: FilterStateRevision::fromInt(2),
            lastAllocatedVersion: $fixture['version'],
            activeVersion: $fixture['version'],
            candidateVersion: null,
            generations: [
                new GenerationControlState(
                    $fixture['version'],
                    LifecycleState::Active,
                    HealthState::Stale,
                ),
            ],
        ),
        FilterStateRevision::fromInt(1),
    );

    $result = (new MemoryAuthorizedProbe(
        $fixture['control'],
        $fixture['contracts'],
        $fixture['bloom'],
    ))->probe($descriptor, $fixture['positions']);

    expect($result->membership())->toBe(Membership::Bypassed)
        ->and($result->bypassReason()?->code())->toBe('control_state_changed');
});

it('never turns missing bloom storage into a trusted negative', function (): void {
    $fixture = task11MemoryFixture();
    $resolution = (new QuerySafetyDescriptorResolver(
        $fixture['control'],
        $fixture['contracts'],
    ))->resolve(
        $fixture['name'],
        task11MemoryContract(),
    );
    $descriptor = $resolution->descriptor();

    if ($descriptor === null) {
        throw new RuntimeException('Expected query safety descriptor.');
    }

    $fixture['bloom']->destroy(
        $fixture['name'],
        $fixture['version'],
    );

    $result = (new MemoryAuthorizedProbe(
        $fixture['control'],
        $fixture['contracts'],
        $fixture['bloom'],
    ))->probe($descriptor, $fixture['positions']);

    expect($result->membership())->toBe(Membership::Bypassed)
        ->and($result->bypassReason()?->code())->toBe('generation_storage_unavailable');
});
