<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\AuthoritativeOutcome;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

it('requires positive synchronization revisions and preserves immutable increment semantics', function (): void {
    $revision = SynchronizationRevision::fromInt(7);

    expect($revision->value())->toBe(7)
        ->and($revision->next()->value())->toBe(8)
        ->and($revision->value())->toBe(7)
        ->and($revision->equals(SynchronizationRevision::fromInt(7)))->toBeTrue()
        ->and($revision->equals(SynchronizationRevision::fromInt(8)))->toBeFalse();

    expect(fn () => SynchronizationRevision::fromInt(0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SynchronizationRevision::fromInt(-1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SynchronizationRevision::fromInt(PHP_INT_MAX)->next())
        ->toThrow(OverflowException::class);
});

it('requires positive synchronization epochs and preserves immutable increment semantics', function (): void {
    $epoch = SynchronizationEpoch::fromInt(3);

    expect($epoch->value())->toBe(3)
        ->and($epoch->next()->value())->toBe(4)
        ->and($epoch->value())->toBe(3)
        ->and($epoch->equals(SynchronizationEpoch::fromInt(3)))->toBeTrue()
        ->and($epoch->equals(SynchronizationEpoch::fromInt(4)))->toBeFalse();

    expect(fn () => SynchronizationEpoch::fromInt(0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SynchronizationEpoch::fromInt(-1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SynchronizationEpoch::fromInt(PHP_INT_MAX)->next())
        ->toThrow(OverflowException::class);
});

it('preserves canonical synchronization target ordering and uniqueness', function (): void {
    $empty = SynchronizationTargetSet::fromVersions([]);
    $targets = SynchronizationTargetSet::fromVersions([
        FilterVersion::fromInt(1),
        FilterVersion::fromInt(3),
        FilterVersion::fromInt(8),
    ]);

    expect($empty->isEmpty())->toBeTrue()
        ->and($empty->versions())->toBe([])
        ->and(array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $targets->versions(),
        ))->toBe([1, 3, 8])
        ->and($targets->contains(FilterVersion::fromInt(3)))->toBeTrue()
        ->and($targets->contains(FilterVersion::fromInt(2)))->toBeFalse()
        ->and($targets->equals(SynchronizationTargetSet::fromVersions([
            FilterVersion::fromInt(1),
            FilterVersion::fromInt(3),
            FilterVersion::fromInt(8),
        ])))->toBeTrue();

    expect(fn () => SynchronizationTargetSet::fromVersions([
        FilterVersion::fromInt(2),
        FilterVersion::fromInt(1),
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SynchronizationTargetSet::fromVersions([
            FilterVersion::fromInt(1),
            FilterVersion::fromInt(1),
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SynchronizationTargetSet::fromVersions(['1']))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts only canonical writer lease tokens', function (): void {
    $token = WriterLeaseToken::fromString('0123456789abcdef0123456789abcdef');

    expect($token->value())->toBe('0123456789abcdef0123456789abcdef')
        ->and($token->equals(WriterLeaseToken::fromString('0123456789abcdef0123456789abcdef')))->toBeTrue()
        ->and($token->equals(WriterLeaseToken::fromString('fedcba9876543210fedcba9876543210')))->toBeFalse();

    expect(fn () => WriterLeaseToken::fromString(''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => WriterLeaseToken::fromString('0123456789abcdef'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => WriterLeaseToken::fromString('0123456789ABCDEF0123456789ABCDEF'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => WriterLeaseToken::fromString('g123456789abcdef0123456789abcdef'))
        ->toThrow(InvalidArgumentException::class);
});

it('defines exactly the durable writer lease states', function (): void {
    expect(array_map(
        static fn (WriterLeaseState $case): string => $case->name,
        WriterLeaseState::cases(),
    ))->toBe([
        'Acquired',
        'Prepared',
        'Released',
    ]);
});

it('defines exactly the M6 synchronization phases', function (): void {
    expect(array_map(
        static fn (SynchronizationPhase $case): string => $case->name,
        SynchronizationPhase::cases(),
    ))->toBe([
        'Steady',
        'DrainingPreReconcile',
        'Reconciling',
        'ReadyToPromote',
        'DrainingPostPromotion',
        'AbortRequested',
        'DrainingAbort',
    ]);
});

it('preserves an immutable writer lease binding across token epoch and exact targets', function (): void {
    $token = WriterLeaseToken::fromString('0123456789abcdef0123456789abcdef');
    $epoch = SynchronizationEpoch::fromInt(11);
    $targets = SynchronizationTargetSet::fromVersions([
        FilterVersion::fromInt(2),
        FilterVersion::fromInt(5),
    ]);

    $lease = new WriterLease(
        token: $token,
        state: WriterLeaseState::Prepared,
        epoch: $epoch,
        targets: $targets,
    );

    expect((new ReflectionClass($lease))->isReadOnly())->toBeTrue()
        ->and($lease->token()->equals($token))->toBeTrue()
        ->and($lease->state())->toBe(WriterLeaseState::Prepared)
        ->and($lease->epoch()->equals($epoch))->toBeTrue()
        ->and($lease->targets()->equals($targets))->toBeTrue();
});

it('represents the strict logical synchronization snapshot without persistence concerns', function (): void {
    $steady = new SynchronizationState(
        revision: SynchronizationRevision::fromInt(1),
        phase: SynchronizationPhase::Steady,
        currentEpoch: SynchronizationEpoch::fromInt(1),
        currentTargets: SynchronizationTargetSet::fromVersions([]),
        candidateVersion: null,
        drainingEpoch: null,
    );

    $published = new SynchronizationState(
        revision: SynchronizationRevision::fromInt(4),
        phase: SynchronizationPhase::DrainingPreReconcile,
        currentEpoch: SynchronizationEpoch::fromInt(8),
        currentTargets: SynchronizationTargetSet::fromVersions([
            FilterVersion::fromInt(3),
            FilterVersion::fromInt(4),
        ]),
        candidateVersion: FilterVersion::fromInt(4),
        drainingEpoch: SynchronizationEpoch::fromInt(7),
    );

    expect($steady->revision()->value())->toBe(1)
        ->and($steady->phase())->toBe(SynchronizationPhase::Steady)
        ->and($steady->currentEpoch()->value())->toBe(1)
        ->and($steady->currentTargets()->isEmpty())->toBeTrue()
        ->and($steady->candidateVersion())->toBeNull()
        ->and($steady->drainingEpoch())->toBeNull()
        ->and($published->revision()->value())->toBe(4)
        ->and($published->phase())->toBe(SynchronizationPhase::DrainingPreReconcile)
        ->and($published->currentEpoch()->value())->toBe(8)
        ->and(array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $published->currentTargets()->versions(),
        ))->toBe([3, 4])
        ->and($published->candidateVersion()?->value())->toBe(4)
        ->and($published->drainingEpoch()?->value())->toBe(7);
});

it('defines exactly the authoritative outcomes required by coordinated writer completion', function (): void {
    expect(array_map(
        static fn (AuthoritativeOutcome $case): string => $case->name,
        AuthoritativeOutcome::cases(),
    ))->toBe([
        'Committed',
        'RolledBack',
        'Unknown',
    ]);
});
