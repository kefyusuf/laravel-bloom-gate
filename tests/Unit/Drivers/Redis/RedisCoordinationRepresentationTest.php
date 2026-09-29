<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinationCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;

function wu03RedisTargets(int ...$versions): SynchronizationTargetSet
{
    return SynchronizationTargetSet::fromVersions(array_map(
        static fn (int $version): FilterVersion => FilterVersion::fromInt($version),
        $versions,
    ));
}

function wu03RedisSync(
    SynchronizationPhase $phase = SynchronizationPhase::DrainingPreReconcile,
): SynchronizationState {
    return new SynchronizationState(
        revision: SynchronizationRevision::fromInt(7),
        phase: $phase,
        currentEpoch: SynchronizationEpoch::fromInt(4),
        currentTargets: wu03RedisTargets(2, 5),
        candidateVersion: FilterVersion::fromInt(5),
        drainingEpoch: SynchronizationEpoch::fromInt(3),
    );
}

function wu03RedisLease(
    WriterLeaseState $state,
): WriterLease {
    return new WriterLease(
        token: WriterLeaseToken::fromString('0123456789abcdef0123456789abcdef'),
        state: $state,
        epoch: SynchronizationEpoch::fromInt(4),
        targets: wu03RedisTargets(2, 5),
    );
}

it('reserves every M6 coordination key under the existing filter hash tag', function (): void {
    $keyspace = RedisKeyspace::fromPrefix('lbg');
    $name = FilterName::fromString('users.email');

    expect($keyspace->syncOwnerKey($name))
        ->toBe('lbg:{users.email}:sync:owner')
        ->and($keyspace->syncKey($name))
        ->toBe('lbg:{users.email}:sync')
        ->and($keyspace->syncStagingKey($name))
        ->toBe('lbg:{users.email}:sync:staging')
        ->and($keyspace->syncLeasesKey($name))
        ->toBe('lbg:{users.email}:sync:leases')
        ->and($keyspace->syncCountsKey($name))
        ->toBe('lbg:{users.email}:sync:counts');

    foreach ([
        $keyspace->syncOwnerKey($name),
        $keyspace->syncKey($name),
        $keyspace->syncStagingKey($name),
        $keyspace->syncLeasesKey($name),
        $keyspace->syncCountsKey($name),
    ] as $key) {
        expect($key)->toContain('{users.email}');
    }
});

it('encodes sync-v1 with canonical fields targets and optional pointers', function (): void {
    $encoded = (new RedisCoordinationCodec)->encodeSynchronization(
        wu03RedisSync(),
    );

    expect($encoded)->toBe([
        'format', 'sync-v1',
        'revision', '7',
        'phase', 'DRAINING_PRE_RECONCILE',
        'current_epoch', '4',
        'current_targets', '2,5',
        'candidate_version', '5',
        'draining_epoch', '3',
    ]);
});

it('uses dash as the only canonical empty target encoding and omits absent optional fields', function (): void {
    $state = new SynchronizationState(
        revision: SynchronizationRevision::fromInt(1),
        phase: SynchronizationPhase::Steady,
        currentEpoch: SynchronizationEpoch::fromInt(1),
        currentTargets: wu03RedisTargets(),
        candidateVersion: null,
        drainingEpoch: null,
    );

    expect((new RedisCoordinationCodec)->encodeSynchronization($state))->toBe([
        'format', 'sync-v1',
        'revision', '1',
        'phase', 'STEADY',
        'current_epoch', '1',
        'current_targets', '-',
    ]);
});

it('round trips every canonical synchronization phase token', function (
    SynchronizationPhase $phase,
    string $token,
): void {
    $codec = new RedisCoordinationCodec;
    $payload = $codec->encodeSynchronization(wu03RedisSync($phase));
    $phaseIndex = array_search('phase', $payload, true);

    if ($phaseIndex === false) {
        throw new RuntimeException('Expected synchronization phase field.');
    }

    expect($payload[$phaseIndex + 1])->toBe($token);

    $decoded = $codec->decodeSynchronization($payload);

    expect($decoded->phase())->toBe($phase);
})->with([
    'steady' => [SynchronizationPhase::Steady, 'STEADY'],
    'draining pre reconcile' => [SynchronizationPhase::DrainingPreReconcile, 'DRAINING_PRE_RECONCILE'],
    'reconciling' => [SynchronizationPhase::Reconciling, 'RECONCILING'],
    'ready to promote' => [SynchronizationPhase::ReadyToPromote, 'READY_TO_PROMOTE'],
    'draining post promotion' => [SynchronizationPhase::DrainingPostPromotion, 'DRAINING_POST_PROMOTION'],
    'abort requested' => [SynchronizationPhase::AbortRequested, 'ABORT_REQUESTED'],
    'draining abort' => [SynchronizationPhase::DrainingAbort, 'DRAINING_ABORT'],
]);

it('decodes strict sync-v1 regardless of redis hash field order', function (): void {
    $decoded = (new RedisCoordinationCodec)->decodeSynchronization([
        'current_targets', '2,5',
        'draining_epoch', '3',
        'phase', 'DRAINING_PRE_RECONCILE',
        'revision', '7',
        'candidate_version', '5',
        'format', 'sync-v1',
        'current_epoch', '4',
    ]);

    expect($decoded->revision()->value())->toBe(7)
        ->and($decoded->phase())->toBe(SynchronizationPhase::DrainingPreReconcile)
        ->and($decoded->currentEpoch()->value())->toBe(4)
        ->and(array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $decoded->currentTargets()->versions(),
        ))->toBe([2, 5])
        ->and($decoded->candidateVersion()?->value())->toBe(5)
        ->and($decoded->drainingEpoch()?->value())->toBe(3);
});

it('rejects unknown duplicate missing or malformed sync-v1 fields', function (array $payload): void {
    expect(fn () => (new RedisCoordinationCodec)->decodeSynchronization($payload))
        ->toThrow(CoordinationStateCorrupt::class);
})->with([
    'unknown field' => [[
        'format', 'sync-v1',
        'revision', '1',
        'phase', 'STEADY',
        'current_epoch', '1',
        'current_targets', '-',
        'ttl', '300',
    ]],
    'duplicate field' => [[
        'format', 'sync-v1',
        'revision', '1',
        'revision', '1',
        'phase', 'STEADY',
        'current_epoch', '1',
        'current_targets', '-',
    ]],
    'missing current targets' => [[
        'format', 'sync-v1',
        'revision', '1',
        'phase', 'STEADY',
        'current_epoch', '1',
    ]],
    'unknown format' => [[
        'format', 'sync-v2',
        'revision', '1',
        'phase', 'STEADY',
        'current_epoch', '1',
        'current_targets', '-',
    ]],
    'unknown phase' => [[
        'format', 'sync-v1',
        'revision', '1',
        'phase', 'steady',
        'current_epoch', '1',
        'current_targets', '-',
    ]],
]);

it('rejects non canonical synchronization numbers and target encodings', function (
    string $field,
    string $value,
): void {
    $payload = [
        'format', 'sync-v1',
        'revision', '1',
        'phase', 'STEADY',
        'current_epoch', '1',
        'current_targets', '-',
    ];

    $index = array_search($field, $payload, true);

    if ($index === false) {
        $payload[] = $field;
        $payload[] = $value;
    } else {
        $payload[$index + 1] = $value;
    }

    expect(fn () => (new RedisCoordinationCodec)->decodeSynchronization($payload))
        ->toThrow(CoordinationStateCorrupt::class);
})->with([
    'revision zero' => ['revision', '0'],
    'revision leading zero' => ['revision', '01'],
    'epoch leading zero' => ['current_epoch', '01'],
    'candidate zero' => ['candidate_version', '0'],
    'draining leading zero' => ['draining_epoch', '01'],
    'target zero' => ['current_targets', '0'],
    'target leading zero' => ['current_targets', '01'],
    'target duplicate' => ['current_targets', '1,1'],
    'target descending' => ['current_targets', '2,1'],
    'target whitespace' => ['current_targets', '1, 2'],
    'target trailing separator' => ['current_targets', '1,'],
]);

it('encodes every durable A-P-R writer lease record canonically', function (
    WriterLeaseState $state,
    string $token,
): void {
    expect((new RedisCoordinationCodec)->encodeLease(
        wu03RedisLease($state),
    ))->toBe($token);
})->with([
    'acquired' => [WriterLeaseState::Acquired, 'A|4|2,5'],
    'prepared' => [WriterLeaseState::Prepared, 'P|4|2,5'],
    'released' => [WriterLeaseState::Released, 'R|4|2,5'],
]);

it('decodes every durable A-P-R writer lease record with the supplied token identity', function (
    string $encoded,
    WriterLeaseState $state,
): void {
    $token = WriterLeaseToken::fromString('0123456789abcdef0123456789abcdef');

    $decoded = (new RedisCoordinationCodec)->decodeLease(
        $token,
        $encoded,
    );

    expect($decoded->token()->equals($token))->toBeTrue()
        ->and($decoded->state())->toBe($state)
        ->and($decoded->epoch()->value())->toBe(4)
        ->and(array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $decoded->targets()->versions(),
        ))->toBe([2, 5]);
})->with([
    'acquired' => ['A|4|2,5', WriterLeaseState::Acquired],
    'prepared' => ['P|4|2,5', WriterLeaseState::Prepared],
    'released' => ['R|4|2,5', WriterLeaseState::Released],
]);

it('rejects malformed writer lease records', function (string $encoded): void {
    expect(fn () => (new RedisCoordinationCodec)->decodeLease(
        WriterLeaseToken::fromString('0123456789abcdef0123456789abcdef'),
        $encoded,
    ))->toThrow(CoordinationStateCorrupt::class);
})->with([
    'unknown state' => 'X|4|2,5',
    'lowercase state' => 'a|4|2,5',
    'zero epoch' => 'A|0|2,5',
    'leading zero epoch' => 'A|04|2,5',
    'duplicate targets' => 'A|4|2,2',
    'descending targets' => 'A|4|5,2',
    'empty targets sentinel malformed' => 'A|4|',
    'extra segment' => 'A|4|2,5|extra',
]);

it('encodes and decodes canonical active-writer count fields and values', function (): void {
    $codec = new RedisCoordinationCodec;

    expect($codec->encodeCountField(SynchronizationEpoch::fromInt(7)))
        ->toBe('e:7')
        ->and($codec->decodeCountField('e:7')->value())
        ->toBe(7)
        ->and($codec->encodeCount(0))
        ->toBe('0')
        ->and($codec->encodeCount(42))
        ->toBe('42')
        ->and($codec->decodeCount('0'))
        ->toBe(0)
        ->and($codec->decodeCount('42'))
        ->toBe(42);
});

it('rejects malformed active-writer count fields and persisted values', function (
    string $kind,
    string $value,
): void {
    $codec = new RedisCoordinationCodec;

    $operation = $kind === 'field'
        ? fn () => $codec->decodeCountField($value)
        : fn () => $codec->decodeCount($value);

    expect($operation)->toThrow(CoordinationStateCorrupt::class);
})->with([
    'field zero' => ['field', 'e:0'],
    'field leading zero' => ['field', 'e:01'],
    'field wrong prefix' => ['field', 'epoch:1'],
    'count negative' => ['value', '-1'],
    'count leading zero' => ['value', '01'],
    'count plus sign' => ['value', '+1'],
    'count empty' => ['value', ''],
]);

it('rejects negative count values before redis persistence', function (): void {
    expect(fn () => (new RedisCoordinationCodec)->encodeCount(-1))
        ->toThrow(InvalidArgumentException::class);
});

it('uses the immutable coordinated ownership marker and rejects every other value', function (): void {
    $codec = new RedisCoordinationCodec;

    expect(RedisCoordinationCodec::OWNER_VALUE)->toBe('coordinated-v1');

    $codec->assertOwnerValue('coordinated-v1');

    expect(fn () => $codec->assertOwnerValue(''))
        ->toThrow(CoordinationStateCorrupt::class)
        ->and(fn () => $codec->assertOwnerValue('coordinated-v2'))
        ->toThrow(CoordinationStateCorrupt::class)
        ->and(fn () => $codec->assertOwnerValue('COORDINATED-V1'))
        ->toThrow(CoordinationStateCorrupt::class);
});

it('never exposes ttl or expiration fields in synchronization or lease representations', function (): void {
    $codec = new RedisCoordinationCodec;
    $sync = $codec->encodeSynchronization(wu03RedisSync());
    $lease = $codec->encodeLease(wu03RedisLease(WriterLeaseState::Prepared));

    expect($sync)->not->toContain('ttl')
        ->and($sync)->not->toContain('expires_at')
        ->and($sync)->not->toContain('expiration')
        ->and($lease)->not->toContain('ttl')
        ->and($lease)->not->toContain('expires');
});
