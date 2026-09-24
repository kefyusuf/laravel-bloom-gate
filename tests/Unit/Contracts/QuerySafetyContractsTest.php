<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;

it('defines a bounded active generation snapshot reader contract', function (): void {
    $contract = new ReflectionClass(ActiveGenerationSnapshotReader::class);
    $method = $contract->getMethod('readActive');
    $parameters = $method->getParameters();
    $returnType = $method->getReturnType();

    if (! $returnType instanceof ReflectionNamedType) {
        throw new RuntimeException('Expected ActiveGenerationSnapshotReader return type.');
    }

    expect($contract->isInterface())->toBeTrue()
        ->and($parameters)->toHaveCount(1)
        ->and((string) $parameters[0]->getType())->toBe(FilterName::class)
        ->and($returnType->getName())->toBe(ActiveGenerationSnapshot::class)
        ->and($returnType->allowsNull())->toBeTrue();
});

it('defines authorized probe without raw application values', function (): void {
    $contract = new ReflectionClass(AuthorizedProbe::class);
    $method = $contract->getMethod('probe');
    $parameters = $method->getParameters();

    expect($contract->isInterface())->toBeTrue()
        ->and($parameters)->toHaveCount(2)
        ->and((string) $parameters[0]->getType())->toBe(QuerySafetyDescriptor::class)
        ->and((string) $parameters[1]->getType())->toBe(BitPositions::class)
        ->and((string) $method->getReturnType())->toBe(AuthorizedProbeResult::class);
});

it('enforces semantic authorized probe result invariants', function (): void {
    $absent = AuthorizedProbeResult::definitelyAbsent();
    $maybe = AuthorizedProbeResult::maybePresent();
    $bypassed = AuthorizedProbeResult::bypassed(
        BypassReason::fromCode('control_state_changed'),
    );

    expect($absent->membership())->toBe(Membership::DefinitelyAbsent)
        ->and($absent->bypassReason())->toBeNull()
        ->and($maybe->membership())->toBe(Membership::MaybePresent)
        ->and($maybe->bypassReason())->toBeNull()
        ->and($bypassed->membership())->toBe(Membership::Bypassed)
        ->and($bypassed->bypassReason()?->code())->toBe('control_state_changed');
});
