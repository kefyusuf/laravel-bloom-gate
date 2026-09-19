<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationEvidenceApplier;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationResult;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;

function activationPassedEvidence(
    FilterName $name,
    FilterVersion $version,
): ActivationVerificationResult {
    return (new ActivationVerifier(
        new BloomProbeGenerator,
        new MemoryBloomDriver,
    ))->verify(
        $name,
        $version,
        BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1),
        [],
    );
}

/**
 * @param list<GenerationControlState> $generations
 */
function activationControlState(
    FilterName $name,
    int $revision,
    FilterVersion $lastAllocated,
    ?FilterVersion $active,
    ?FilterVersion $candidate,
    array $generations,
): FilterControlState {
    return new FilterControlState(
        filterName: $name,
        revision: FilterStateRevision::fromInt($revision),
        lastAllocatedVersion: $lastAllocated,
        activeVersion: $active,
        candidateVersion: $candidate,
        generations: $generations,
    );
}

it('applies passed evidence only to the same current shadow candidate', function (): void {
    $name = FilterName::fromString('products.sku');
    $active = FilterVersion::fromInt(1);
    $candidate = FilterVersion::fromInt(2);
    $state = activationControlState(
        name: $name,
        revision: 6,
        lastAllocated: $candidate,
        active: $active,
        candidate: $candidate,
        generations: [
            new GenerationControlState(
                version: $active,
                lifecycle: LifecycleState::Active,
                health: HealthState::Healthy,
            ),
            new GenerationControlState(
                version: $candidate,
                lifecycle: LifecycleState::Shadow,
                health: HealthState::Degraded,
            ),
        ],
    );

    $next = (new ActivationVerificationEvidenceApplier)->apply(
        $state,
        activationPassedEvidence($name, $candidate),
    );

    expect($next)->not->toBe($state)
        ->and($next->revision()->value())->toBe(7)
        ->and($next->filterName())->toBe($name)
        ->and($next->lastAllocatedVersion())->toBe($candidate)
        ->and($next->activeVersion())->toBe($active)
        ->and($next->candidateVersion())->toBe($candidate)
        ->and($next->generations()[0]->lifecycle())->toBe(LifecycleState::Active)
        ->and($next->generations()[0]->health())->toBe(HealthState::Healthy)
        ->and($next->generations()[1]->lifecycle())->toBe(LifecycleState::Verified)
        ->and($next->generations()[1]->health())->toBe(HealthState::Degraded)
        ->and($state->revision()->value())->toBe(6)
        ->and($state->generations()[1]->lifecycle())->toBe(LifecycleState::Shadow);
});

it('rejects verification evidence for a different filter name', function (): void {
    $stateName = FilterName::fromString('products.sku');
    $evidenceName = FilterName::fromString('Products.Sku');
    $candidate = FilterVersion::fromInt(1);
    $state = activationControlState(
        $stateName,
        1,
        $candidate,
        null,
        $candidate,
        [
            new GenerationControlState(
                $candidate,
                LifecycleState::Shadow,
                HealthState::Healthy,
            ),
        ],
    );

    expect(fn () => (new ActivationVerificationEvidenceApplier)->apply(
        $state,
        activationPassedEvidence($evidenceName, $candidate),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects verification evidence when the candidate version changed', function (): void {
    $name = FilterName::fromString('products.sku');
    $oldCandidate = FilterVersion::fromInt(1);
    $currentCandidate = FilterVersion::fromInt(2);
    $state = activationControlState(
        $name,
        2,
        $currentCandidate,
        null,
        $currentCandidate,
        [
            new GenerationControlState(
                $oldCandidate,
                LifecycleState::Retired,
                HealthState::Unavailable,
            ),
            new GenerationControlState(
                $currentCandidate,
                LifecycleState::Shadow,
                HealthState::Healthy,
            ),
        ],
    );

    expect(fn () => (new ActivationVerificationEvidenceApplier)->apply(
        $state,
        activationPassedEvidence($name, $oldCandidate),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects verification evidence when the candidate is no longer tracked', function (): void {
    $name = FilterName::fromString('products.sku');
    $oldCandidate = FilterVersion::fromInt(1);
    $lastAllocated = FilterVersion::fromInt(2);
    $state = activationControlState(
        $name,
        3,
        $lastAllocated,
        null,
        null,
        [],
    );

    expect(fn () => (new ActivationVerificationEvidenceApplier)->apply(
        $state,
        activationPassedEvidence($name, $oldCandidate),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects verification evidence when the current candidate is no longer shadow', function (
    LifecycleState $lifecycle,
): void {
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(1);
    $state = activationControlState(
        $name,
        2,
        $candidate,
        null,
        $candidate,
        [
            new GenerationControlState(
                $candidate,
                $lifecycle,
                HealthState::Healthy,
            ),
        ],
    );

    expect(fn () => (new ActivationVerificationEvidenceApplier)->apply(
        $state,
        activationPassedEvidence($name, $candidate),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    LifecycleState::Configured,
    LifecycleState::Building,
    LifecycleState::Verified,
]);

it('does not invalidate version bound evidence because health changed', function (): void {
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(1);
    $state = activationControlState(
        $name,
        4,
        $candidate,
        null,
        $candidate,
        [
            new GenerationControlState(
                $candidate,
                LifecycleState::Shadow,
                HealthState::Stale,
            ),
        ],
    );

    $next = (new ActivationVerificationEvidenceApplier)->apply(
        $state,
        activationPassedEvidence($name, $candidate),
    );

    expect($next->generations()[0]->lifecycle())->toBe(LifecycleState::Verified)
        ->and($next->generations()[0]->health())->toBe(HealthState::Stale);
});

it('rejects false negative evidence', function (): void {
    $name = FilterName::fromString('products.sku');
    $candidate = FilterVersion::fromInt(1);
    $layout = BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1);
    $driver = new MemoryBloomDriver;
    $driver->provision($name, $candidate, $layout);

    $failedEvidence = (new ActivationVerifier(
        new BloomProbeGenerator,
        $driver,
    ))->verify(
        $name,
        $candidate,
        $layout,
        [NormalizedValue::fromBytes('missing')],
    );

    expect($failedEvidence->status())->toBe(
        ActivationVerificationStatus::FalseNegativeDetected,
    );

    $state = activationControlState(
        $name,
        1,
        $candidate,
        null,
        $candidate,
        [
            new GenerationControlState(
                $candidate,
                LifecycleState::Shadow,
                HealthState::Healthy,
            ),
        ],
    );

    expect(fn () => (new ActivationVerificationEvidenceApplier)->apply(
        $state,
        $failedEvidence,
    ))->toThrow(InvalidArgumentException::class);
});
