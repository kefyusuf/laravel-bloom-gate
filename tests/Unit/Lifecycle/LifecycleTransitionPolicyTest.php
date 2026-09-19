<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;

$genericEdges = [
    'Configured->Building',
    'Configured->Retired',
    'Building->Shadow',
    'Building->Retired',
    'Shadow->Retired',
    'Verified->Retired',
    'Active->Retired',
];

$matrix = [];

foreach (LifecycleState::cases() as $from) {
    foreach (LifecycleState::cases() as $to) {
        $edge = $from->name.'->'.$to->name;

        $matrix[$edge] = [
            $from,
            $to,
            in_array($edge, $genericEdges, true),
        ];
    }
}

it('enforces exactly the generic lifecycle transition matrix', function (
    LifecycleState $from,
    LifecycleState $to,
    bool $allowed,
): void {
    $policy = new LifecycleTransitionPolicy;
    $version = FilterVersion::fromInt(7);
    $current = new GenerationControlState(
        version: $version,
        lifecycle: $from,
        health: HealthState::Degraded,
    );

    if ($allowed === false) {
        expect(
            fn (): GenerationControlState => $policy->transition($current, $to),
        )->toThrow(InvalidArgumentException::class);

        return;
    }

    $next = $policy->transition($current, $to);

    expect($next)->not->toBe($current)
        ->and($next->version())->toBe($version)
        ->and($next->lifecycle())->toBe($to)
        ->and($next->health())->toBe(HealthState::Degraded)
        ->and($current->lifecycle())->toBe($from)
        ->and($current->health())->toBe(HealthState::Degraded);
})->with($matrix);

it('rejects evidence free shadow to verified through the generic API', function (): void {
    $policy = new LifecycleTransitionPolicy;
    $current = new GenerationControlState(
        version: FilterVersion::fromInt(1),
        lifecycle: LifecycleState::Shadow,
        health: HealthState::Healthy,
    );

    expect(
        fn (): GenerationControlState => $policy->transition(
            $current,
            LifecycleState::Verified,
        ),
    )->toThrow(InvalidArgumentException::class);
});

it('rejects direct verified to active through the generic API', function (): void {
    $policy = new LifecycleTransitionPolicy;
    $current = new GenerationControlState(
        version: FilterVersion::fromInt(1),
        lifecycle: LifecycleState::Verified,
        health: HealthState::Healthy,
    );

    expect(
        fn (): GenerationControlState => $policy->transition(
            $current,
            LifecycleState::Active,
        ),
    )->toThrow(InvalidArgumentException::class);
});

it('keeps retired terminal in the m4 generic policy', function (LifecycleState $target): void {
    $policy = new LifecycleTransitionPolicy;
    $current = new GenerationControlState(
        version: FilterVersion::fromInt(1),
        lifecycle: LifecycleState::Retired,
        health: HealthState::Unavailable,
    );

    expect(
        fn (): GenerationControlState => $policy->transition($current, $target),
    )->toThrow(InvalidArgumentException::class);
})->with(LifecycleState::cases());
