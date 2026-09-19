<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;

function generation(
    int $version,
    LifecycleState $lifecycle,
    HealthState $health = HealthState::Healthy,
): GenerationControlState {
    return new GenerationControlState(
        version: FilterVersion::fromInt($version),
        lifecycle: $lifecycle,
        health: $health,
    );
}

it('retains exact identity revision allocation waterline and generation snapshot', function (): void {
    $name = FilterName::fromString('products.sku');
    $revision = FilterStateRevision::fromInt(9);
    $lastAllocated = FilterVersion::fromInt(4);
    $active = FilterVersion::fromInt(3);
    $candidate = FilterVersion::fromInt(4);
    $generations = [
        generation(1, LifecycleState::Retired),
        generation(3, LifecycleState::Active),
        generation(4, LifecycleState::Shadow, HealthState::Degraded),
    ];

    $state = new FilterControlState(
        filterName: $name,
        revision: $revision,
        lastAllocatedVersion: $lastAllocated,
        activeVersion: $active,
        candidateVersion: $candidate,
        generations: $generations,
    );

    expect($state->filterName())->toBe($name)
        ->and($state->revision())->toBe($revision)
        ->and($state->lastAllocatedVersion())->toBe($lastAllocated)
        ->and($state->activeVersion())->toBe($active)
        ->and($state->candidateVersion())->toBe($candidate)
        ->and($state->generations())->toBe($generations);
});

it('allows no active or candidate generation', function (): void {
    $state = new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(3),
        activeVersion: null,
        candidateVersion: null,
        generations: [],
    );

    expect($state->activeVersion())->toBeNull()
        ->and($state->candidateVersion())->toBeNull()
        ->and($state->generations())->toBe([]);
});

it('allows one active generation', function (): void {
    $active = FilterVersion::fromInt(3);

    $state = new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(3),
        activeVersion: $active,
        candidateVersion: null,
        generations: [generation(3, LifecycleState::Active)],
    );

    expect($state->activeVersion())->toBe($active);
});

it('allows one candidate generation', function (): void {
    $candidate = FilterVersion::fromInt(3);

    $state = new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(3),
        activeVersion: null,
        candidateVersion: $candidate,
        generations: [generation(3, LifecycleState::Shadow)],
    );

    expect($state->candidateVersion())->toBe($candidate);
});

it('allows distinct active and candidate generations simultaneously', function (): void {
    $active = FilterVersion::fromInt(2);
    $candidate = FilterVersion::fromInt(3);

    $state = new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(3),
        activeVersion: $active,
        candidateVersion: $candidate,
        generations: [
            generation(2, LifecycleState::Active),
            generation(3, LifecycleState::Verified),
        ],
    );

    expect($state->activeVersion())->toBe($active)
        ->and($state->candidateVersion())->toBe($candidate);
});

it('rejects duplicate tracked generation versions', function (): void {
    new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(2),
        activeVersion: null,
        candidateVersion: null,
        generations: [
            generation(1, LifecycleState::Retired),
            generation(1, LifecycleState::Retired),
        ],
    );
})->throws(InvalidArgumentException::class);

it('rejects an active pointer to an untracked generation', function (): void {
    new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(2),
        activeVersion: FilterVersion::fromInt(2),
        candidateVersion: null,
        generations: [generation(1, LifecycleState::Retired)],
    );
})->throws(InvalidArgumentException::class);

it('rejects a candidate pointer to an untracked generation', function (): void {
    new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(2),
        activeVersion: null,
        candidateVersion: FilterVersion::fromInt(2),
        generations: [generation(1, LifecycleState::Retired)],
    );
})->throws(InvalidArgumentException::class);

it('rejects an active pointer to a non active lifecycle', function (): void {
    new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(1),
        activeVersion: FilterVersion::fromInt(1),
        candidateVersion: null,
        generations: [generation(1, LifecycleState::Shadow)],
    );
})->throws(InvalidArgumentException::class);

it('rejects a candidate pointer to an active lifecycle', function (): void {
    new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(1),
        activeVersion: null,
        candidateVersion: FilterVersion::fromInt(1),
        generations: [generation(1, LifecycleState::Active)],
    );
})->throws(InvalidArgumentException::class);

it('rejects a candidate pointer to a retired lifecycle', function (): void {
    new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(1),
        activeVersion: null,
        candidateVersion: FilterVersion::fromInt(1),
        generations: [generation(1, LifecycleState::Retired)],
    );
})->throws(InvalidArgumentException::class);

it('rejects the same version as active and candidate', function (): void {
    new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(1),
        activeVersion: FilterVersion::fromInt(1),
        candidateVersion: FilterVersion::fromInt(1),
        generations: [generation(1, LifecycleState::Active)],
    );
})->throws(InvalidArgumentException::class);

it('rejects a tracked version above the allocation waterline', function (): void {
    new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(1),
        activeVersion: null,
        candidateVersion: null,
        generations: [generation(2, LifecycleState::Retired)],
    );
})->throws(InvalidArgumentException::class);

it('allows gaps below the last allocated version', function (): void {
    $state = new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(5),
        activeVersion: FilterVersion::fromInt(5),
        candidateVersion: null,
        generations: [
            generation(1, LifecycleState::Retired),
            generation(5, LifecycleState::Active),
        ],
    );

    expect(array_map(
        static fn (GenerationControlState $generation): int => $generation->version()->value(),
        $state->generations(),
    ))->toBe([1, 5]);
});

it('does not require every retired generation to remain tracked forever', function (): void {
    $state = new FilterControlState(
        filterName: FilterName::fromString('products.sku'),
        revision: FilterStateRevision::fromInt(1),
        lastAllocatedVersion: FilterVersion::fromInt(5),
        activeVersion: FilterVersion::fromInt(5),
        candidateVersion: null,
        generations: [generation(5, LifecycleState::Active)],
    );

    expect($state->lastAllocatedVersion()->value())->toBe(5)
        ->and($state->generations())->toHaveCount(1);
});
