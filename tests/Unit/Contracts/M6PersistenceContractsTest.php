<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Contracts\Exception\WriterLeaseReleased;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Tests\Contract\CoordinatedLifecycleStoreContractTestCase;
use Kefyusuf\BloomGate\Tests\Contract\WriterSynchronizationStoreContractTestCase;

function wu01NamedType(ReflectionMethod $method, int $parameter): ReflectionNamedType
{
    $type = $method->getParameters()[$parameter]->getType();

    if (! $type instanceof ReflectionNamedType) {
        throw new RuntimeException(sprintf(
            'Expected named parameter type for %s::%s parameter %d.',
            $method->getDeclaringClass()->getName(),
            $method->getName(),
            $parameter,
        ));
    }

    return $type;
}

it('defines coordinated lifecycle persistence as a framework-neutral additive port', function (): void {
    $contract = new ReflectionClass(CoordinatedLifecycleStore::class);

    expect($contract->isInterface())->toBeTrue();

    $read = $contract->getMethod('read');
    $claim = $contract->getMethod('claimOwnership');
    $control = $contract->getMethod('compareAndSwapControl');
    $sync = $contract->getMethod('compareAndSwapSynchronization');

    expect($read->getNumberOfParameters())->toBe(1)
        ->and(wu01NamedType($read, 0)->getName())->toBe(FilterName::class)
        ->and((string) $read->getReturnType())->toBe(CoordinatedLifecycleSnapshot::class)
        ->and($claim->getNumberOfParameters())->toBe(2)
        ->and(wu01NamedType($claim, 0)->getName())->toBe(FilterName::class)
        ->and(wu01NamedType($claim, 1)->getName())->toBe(FilterStateRevision::class)
        ->and(wu01NamedType($claim, 1)->allowsNull())->toBeTrue()
        ->and((string) $claim->getReturnType())->toBe(CoordinatedLifecycleSnapshot::class)
        ->and($control->getNumberOfParameters())->toBe(4)
        ->and(wu01NamedType($control, 0)->getName())->toBe(FilterName::class)
        ->and(wu01NamedType($control, 1)->getName())->toBe(FilterControlState::class)
        ->and(wu01NamedType($control, 2)->getName())->toBe(FilterStateRevision::class)
        ->and(wu01NamedType($control, 2)->allowsNull())->toBeTrue()
        ->and(wu01NamedType($control, 3)->getName())->toBe(SynchronizationRevision::class)
        ->and((string) $control->getReturnType())->toBe(CoordinatedLifecycleSnapshot::class)
        ->and($sync->getNumberOfParameters())->toBe(4)
        ->and(wu01NamedType($sync, 0)->getName())->toBe(FilterName::class)
        ->and(wu01NamedType($sync, 1)->getName())->toBe(SynchronizationState::class)
        ->and(wu01NamedType($sync, 2)->getName())->toBe(SynchronizationRevision::class)
        ->and(wu01NamedType($sync, 2)->allowsNull())->toBeTrue()
        ->and(wu01NamedType($sync, 3)->getName())->toBe(FilterStateRevision::class)
        ->and(wu01NamedType($sync, 3)->allowsNull())->toBeTrue()
        ->and((string) $sync->getReturnType())->toBe(CoordinatedLifecycleSnapshot::class);
});

it('defines writer synchronization as dedicated atomic store operations', function (): void {
    $contract = new ReflectionClass(WriterSynchronizationStore::class);

    expect($contract->isInterface())->toBeTrue();

    $expectedMethods = [
        'acquire',
        'activeWriterCount',
        'markPrepared',
        'read',
        'release',
    ];

    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $contract->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($methods);

    expect($methods)->toBe($expectedMethods);

    $read = $contract->getMethod('read');
    $acquire = $contract->getMethod('acquire');
    $prepare = $contract->getMethod('markPrepared');
    $release = $contract->getMethod('release');
    $count = $contract->getMethod('activeWriterCount');

    $readReturn = $read->getReturnType();

    if (! $readReturn instanceof ReflectionNamedType) {
        throw new RuntimeException('Expected synchronization read return type.');
    }

    expect($read->getNumberOfParameters())->toBe(1)
        ->and(wu01NamedType($read, 0)->getName())->toBe(FilterName::class)
        ->and($readReturn->getName())->toBe(SynchronizationState::class)
        ->and($readReturn->allowsNull())->toBeTrue()
        ->and($acquire->getNumberOfParameters())->toBe(2)
        ->and(wu01NamedType($acquire, 0)->getName())->toBe(FilterName::class)
        ->and(wu01NamedType($acquire, 1)->getName())->toBe(WriterLeaseToken::class)
        ->and((string) $acquire->getReturnType())->toBe(WriterLease::class)
        ->and($prepare->getNumberOfParameters())->toBe(2)
        ->and(wu01NamedType($prepare, 1)->getName())->toBe(WriterLeaseToken::class)
        ->and((string) $prepare->getReturnType())->toBe(WriterLease::class)
        ->and($release->getNumberOfParameters())->toBe(2)
        ->and(wu01NamedType($release, 1)->getName())->toBe(WriterLeaseToken::class)
        ->and((string) $release->getReturnType())->toBe(WriterLease::class)
        ->and($count->getNumberOfParameters())->toBe(2)
        ->and(wu01NamedType($count, 1)->getName())->toBe(SynchronizationEpoch::class)
        ->and((string) $count->getReturnType())->toBe('int');
});

it('represents only valid coordinated lifecycle observations', function (): void {
    $snapshot = new CoordinatedLifecycleSnapshot(
        ownershipClaimed: false,
        control: null,
        synchronization: null,
    );

    expect($snapshot->ownershipClaimed())->toBeFalse()
        ->and($snapshot->control())->toBeNull()
        ->and($snapshot->synchronization())->toBeNull();

    expect(fn () => new CoordinatedLifecycleSnapshot(
        ownershipClaimed: false,
        control: null,
        synchronization: new SynchronizationState(
            revision: SynchronizationRevision::fromInt(1),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt(1),
            currentTargets: SynchronizationTargetSet::fromVersions([]),
            candidateVersion: null,
            drainingEpoch: null,
        ),
    ))->toThrow(InvalidArgumentException::class);
});

it('defines distinct coordination persistence failure categories', function (): void {
    foreach ([
        CoordinationWriteConflict::class,
        CoordinationFenced::class,
        CoordinationStateCorrupt::class,
        CoordinationStoreOperationFailed::class,
        WriterLeaseReleased::class,
        UnknownWriterLease::class,
    ] as $exception) {
        $reflection = new ReflectionClass($exception);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isSubclassOf(RuntimeException::class))->toBeTrue();
    }
});

it('provides backend-neutral reusable lifecycle and writer contract suites', function (): void {
    $lifecycle = new ReflectionClass(CoordinatedLifecycleStoreContractTestCase::class);
    $writer = new ReflectionClass(WriterSynchronizationStoreContractTestCase::class);

    expect($lifecycle->isAbstract())->toBeTrue()
        ->and($writer->isAbstract())->toBeTrue();

    foreach ([
        'test_never_adopted_read_preserves_control_and_absent_coordination',
        'test_claim_ownership_is_one_way_and_requires_sync_absence',
        'test_claim_ownership_conflicts_on_stale_control_relation',
        'test_coordinated_control_cas_is_guarded_by_exact_sync_revision',
        'test_coordinated_control_cas_treats_null_control_revision_as_required_absence',
        'test_coordinated_sync_cas_treats_null_control_revision_as_required_absence',
        'test_coordinated_sync_cas_treats_null_sync_revision_as_required_absence',
        'test_owner_sync_contradiction_fails_closed',
        'test_staging_state_is_not_current_correctness_state',
    ] as $method) {
        expect($lifecycle->hasMethod($method))->toBeTrue($method);
    }

    foreach ([
        'test_acquire_binds_current_epoch_and_targets_atomically',
        'test_acquire_requires_valid_owner_and_sync',
        'test_acquire_retry_preserves_original_binding_and_count',
        'test_acquire_retry_on_prepared_lease_preserves_original_binding_and_count',
        'test_mark_prepared_is_idempotent_and_count_neutral',
        'test_mark_prepared_requires_valid_owner_sync_and_positive_original_epoch_count',
        'test_acquired_lease_can_release_once_without_preparation',
        'test_acquired_lease_releases_against_original_epoch_after_rotation',
        'test_first_release_decrements_once_and_retry_is_idempotent',
        'test_unknown_token_is_distinct',
        'test_released_token_is_terminal',
        'test_count_underflow_is_corruption',
    ] as $method) {
        expect($writer->hasMethod($method))->toBeTrue($method);
    }
});
