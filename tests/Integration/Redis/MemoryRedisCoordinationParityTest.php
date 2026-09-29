<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Contracts\Exception\WriterLeaseReleased;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Tests\Support\Parity\CoordinationParityHarness;
use Kefyusuf\BloomGate\Tests\Support\Parity\MemoryCoordinationParityHarness;
use Kefyusuf\BloomGate\Tests\Support\Parity\RedisCoordinationParityHarness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[Group('redis')]
final class MemoryRedisCoordinationParityTest extends TestCase
{
    public function test_p01_uncoordinated_ordinary_control_cas_is_eligible(): void
    {
        $this->assertParity(
            'P01',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->control()->compareAndSwap(
                    $name,
                    $this->controlState($name, 1),
                    null,
                );

                return [
                    'revision' => $harness->control()->read($name)?->revision()->value(),
                ];
            },
            ['revision' => 1],
        );
    }

    public function test_p02_valid_coordination_fences_ordinary_control_cas(): void
    {
        $this->assertParity(
            'P02',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putControl($name, $this->controlState($name, 1));
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1]),
                );

                $outcome = $this->outcome(fn () => $harness->control()->compareAndSwap(
                    $name,
                    $this->controlState($name, 2),
                    FilterStateRevision::fromInt(1),
                ));

                return [
                    'outcome' => $outcome,
                    'revision' => $harness->control()->read($name)?->revision()->value(),
                ];
            },
            [
                'outcome' => 'fenced',
                'revision' => 1,
            ],
        );
    }

    public function test_p03_owner_without_sync_blocks_legacy_and_writer_mutation(): void
    {
        $this->assertParity(
            'P03',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putControl($name, $this->controlState($name, 1));
                $harness->putCoordination($name, true, null);

                $legacy = $this->outcome(fn () => $harness->control()->compareAndSwap(
                    $name,
                    $this->controlState($name, 2),
                    FilterStateRevision::fromInt(1),
                ));
                $writer = $this->outcome(fn () => $harness->writer()->acquire(
                    $name,
                    $this->token('3'),
                ));
                $snapshot = $harness->lifecycle()->read($name);

                return [
                    'legacy' => $legacy,
                    'writer' => $writer,
                    'owner' => $snapshot->ownershipClaimed(),
                    'sync' => $snapshot->synchronization()?->revision()->value(),
                    'control_revision' => $harness->control()->read($name)?->revision()->value(),
                ];
            },
            [
                'legacy' => 'fenced',
                'writer' => 'fenced',
                'owner' => true,
                'sync' => null,
                'control_revision' => 1,
            ],
        );
    }

    public function test_p04_sync_without_owner_is_corruption_and_does_not_mutate(): void
    {
        $this->assertParity(
            'P04',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putControl($name, $this->controlState($name, 1));
                $harness->putCoordination(
                    $name,
                    false,
                    $this->syncState(1, 1, [1]),
                );

                return [
                    'legacy' => $this->outcome(fn () => $harness->control()->compareAndSwap(
                        $name,
                        $this->controlState($name, 2),
                        FilterStateRevision::fromInt(1),
                    )),
                    'read' => $this->outcome(fn () => $harness->lifecycle()->read($name)),
                    'control_revision' => $harness->control()->read($name)?->revision()->value(),
                ];
            },
            [
                'legacy' => 'corrupt',
                'read' => 'corrupt',
                'control_revision' => 1,
            ],
        );
    }

    public function test_p05_ownership_claim_is_one_way(): void
    {
        $this->assertParity(
            'P05',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $claimed = $harness->lifecycle()->claimOwnership($name, null);
                $retry = $this->outcome(
                    fn () => $harness->lifecycle()->claimOwnership($name, null),
                );

                return [
                    'owner' => $claimed->ownershipClaimed(),
                    'retry' => $retry,
                    'sync' => $harness->lifecycle()->read($name)->synchronization()?->revision()->value(),
                ];
            },
            [
                'owner' => true,
                'retry' => 'fenced',
                'sync' => null,
            ],
        );
    }

    public function test_p06_atomic_pair_read_observes_one_control_sync_snapshot(): void
    {
        $this->assertParity(
            'P06',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putControl($name, $this->controlState($name, 10));
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(7, 1, [1]),
                );

                return $this->snapshotTrace(
                    $harness->lifecycle()->read($name),
                );
            },
            [
                'owner' => true,
                'control_revision' => 10,
                'sync_revision' => 7,
                'epoch' => 1,
                'targets' => [1],
            ],
        );
    }

    public function test_p07_opposite_plane_cas_loser_conflicts_in_both_orders(): void
    {
        $this->assertParity(
            'P07',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $controlFirst = FilterName::fromString($name->value().'.control');
                $harness->putControl($controlFirst, $this->controlState($controlFirst, 10));
                $harness->putCoordination(
                    $controlFirst,
                    true,
                    $this->syncState(7, 1, [1]),
                );

                $harness->lifecycle()->compareAndSwapControl(
                    $controlFirst,
                    $this->controlState($controlFirst, 11),
                    FilterStateRevision::fromInt(10),
                    SynchronizationRevision::fromInt(7),
                );
                $controlFirstLoser = $this->outcome(
                    fn () => $harness->lifecycle()->compareAndSwapSynchronization(
                        $controlFirst,
                        $this->syncState(8, 2, [2]),
                        SynchronizationRevision::fromInt(7),
                        FilterStateRevision::fromInt(10),
                    ),
                );

                $syncFirst = FilterName::fromString($name->value().'.sync');
                $harness->putControl($syncFirst, $this->controlState($syncFirst, 10));
                $harness->putCoordination(
                    $syncFirst,
                    true,
                    $this->syncState(7, 1, [1]),
                );

                $harness->lifecycle()->compareAndSwapSynchronization(
                    $syncFirst,
                    $this->syncState(8, 2, [2]),
                    SynchronizationRevision::fromInt(7),
                    FilterStateRevision::fromInt(10),
                );
                $syncFirstLoser = $this->outcome(
                    fn () => $harness->lifecycle()->compareAndSwapControl(
                        $syncFirst,
                        $this->controlState($syncFirst, 11),
                        FilterStateRevision::fromInt(10),
                        SynchronizationRevision::fromInt(7),
                    ),
                );

                return [
                    'control_first_loser' => $controlFirstLoser,
                    'control_first_snapshot' => $this->snapshotTrace(
                        $harness->lifecycle()->read($controlFirst),
                    ),
                    'sync_first_loser' => $syncFirstLoser,
                    'sync_first_snapshot' => $this->snapshotTrace(
                        $harness->lifecycle()->read($syncFirst),
                    ),
                ];
            },
            [
                'control_first_loser' => 'conflict',
                'control_first_snapshot' => [
                    'owner' => true,
                    'control_revision' => 11,
                    'sync_revision' => 7,
                    'epoch' => 1,
                    'targets' => [1],
                ],
                'sync_first_loser' => 'conflict',
                'sync_first_snapshot' => [
                    'owner' => true,
                    'control_revision' => 10,
                    'sync_revision' => 8,
                    'epoch' => 2,
                    'targets' => [2],
                ],
            ],
        );
    }

    public function test_p08_null_opposite_revision_means_required_absence(): void
    {
        $this->assertParity(
            'P08',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $absent = FilterName::fromString($name->value().'.absent');
                $harness->putCoordination(
                    $absent,
                    true,
                    $this->syncState(7, 1, [1]),
                );
                $absenceResult = $this->outcome(
                    fn () => $harness->lifecycle()->compareAndSwapSynchronization(
                        $absent,
                        $this->syncState(8, 2, [2]),
                        SynchronizationRevision::fromInt(7),
                        null,
                    ),
                );

                $present = FilterName::fromString($name->value().'.present');
                $harness->putControl($present, $this->controlState($present, 10));
                $harness->putCoordination(
                    $present,
                    true,
                    $this->syncState(7, 1, [1]),
                );
                $presentResult = $this->outcome(
                    fn () => $harness->lifecycle()->compareAndSwapSynchronization(
                        $present,
                        $this->syncState(8, 2, [2]),
                        SynchronizationRevision::fromInt(7),
                        null,
                    ),
                );

                return [
                    'absent' => $absenceResult,
                    'present' => $presentResult,
                ];
            },
            [
                'absent' => 'ok',
                'present' => 'conflict',
            ],
        );
    }

    public function test_p09_new_acquire_binds_exact_epoch_targets_and_increments_once(): void
    {
        $this->assertParity(
            'P09',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );
                $lease = $harness->writer()->acquire($name, $this->token('9'));

                return [
                    'lease' => $this->leaseTrace($lease),
                    'count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            [
                'lease' => [
                    'state' => 'A',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'count' => 1,
            ],
        );
    }

    public function test_p10_acquire_retry_preserves_original_binding_without_double_count(): void
    {
        $this->assertParity(
            'P10',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );
                $token = $this->token('a');
                $first = $harness->writer()->acquire($name, $token);

                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(2, 2, [2]),
                );
                $retry = $harness->writer()->acquire($name, $token);

                return [
                    'first' => $this->leaseTrace($first),
                    'retry' => $this->leaseTrace($retry),
                    'old_count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                    'new_count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(2),
                    ),
                ];
            },
            [
                'first' => [
                    'state' => 'A',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'retry' => [
                    'state' => 'A',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'old_count' => 1,
                'new_count' => 0,
            ],
        );
    }

    public function test_p11_prepare_transitions_a_to_p_without_count_change(): void
    {
        $this->assertParity(
            'P11',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );
                $token = $this->token('b');
                $harness->writer()->acquire($name, $token);
                $prepared = $harness->writer()->markPrepared($name, $token);

                return [
                    'lease' => $this->leaseTrace($prepared),
                    'count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            [
                'lease' => [
                    'state' => 'P',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'count' => 1,
            ],
        );
    }

    public function test_p11a_prepare_with_invalid_ownership_is_rejected_without_mutation(): void
    {
        $this->assertParity(
            'P11a',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );
                $token = $this->token('c');
                $harness->writer()->acquire($name, $token);

                $harness->putCoordination($name, false, null);
                $result = $this->outcome(
                    fn () => $harness->writer()->markPrepared($name, $token),
                );

                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );

                return [
                    'outcome' => $result,
                    'lease' => $this->leaseTrace(
                        $harness->writer()->acquire($name, $token),
                    ),
                    'count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            [
                'outcome' => 'fenced',
                'lease' => [
                    'state' => 'A',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'count' => 1,
            ],
        );
    }

    public function test_p11b_prepare_with_missing_current_sync_is_rejected_without_mutation(): void
    {
        $this->assertParity(
            'P11b',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );
                $token = $this->token('d');
                $harness->writer()->acquire($name, $token);

                $harness->putCoordination($name, true, null);
                $result = $this->outcome(
                    fn () => $harness->writer()->markPrepared($name, $token),
                );

                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );

                return [
                    'outcome' => $result,
                    'lease' => $this->leaseTrace(
                        $harness->writer()->acquire($name, $token),
                    ),
                    'count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            [
                'outcome' => 'fenced',
                'lease' => [
                    'state' => 'A',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'count' => 1,
            ],
        );
    }

    public function test_p11c_prepare_requires_positive_original_epoch_count(): void
    {
        $this->assertParity(
            'P11c',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );
                $token = $this->token('e');
                $harness->writer()->acquire($name, $token);
                $harness->setActiveWriterCount(
                    $name,
                    SynchronizationEpoch::fromInt(1),
                    0,
                );

                return [
                    'outcome' => $this->outcome(
                        fn () => $harness->writer()->markPrepared($name, $token),
                    ),
                    'lease' => $this->leaseTrace(
                        $harness->writer()->acquire($name, $token),
                    ),
                    'count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            [
                'outcome' => 'corrupt',
                'lease' => [
                    'state' => 'A',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'count' => 0,
            ],
        );
    }

    public function test_p12_prepared_retries_preserve_binding_and_count(): void
    {
        $this->assertParity(
            'P12',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );
                $token = $this->token('f');
                $harness->writer()->acquire($name, $token);
                $harness->writer()->markPrepared($name, $token);

                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(2, 2, [2]),
                );
                $acquireRetry = $harness->writer()->acquire($name, $token);
                $prepareRetry = $harness->writer()->markPrepared($name, $token);

                return [
                    'acquire_retry' => $this->leaseTrace($acquireRetry),
                    'prepare_retry' => $this->leaseTrace($prepareRetry),
                    'old_count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                    'new_count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(2),
                    ),
                ];
            },
            [
                'acquire_retry' => [
                    'state' => 'P',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'prepare_retry' => [
                    'state' => 'P',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'old_count' => 1,
                'new_count' => 0,
            ],
        );
    }

    public function test_p13_release_acquired_lease_decrements_once(): void
    {
        $this->assertParity(
            'P13',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1]),
                );
                $token = $this->token('1');
                $harness->writer()->acquire($name, $token);
                $released = $harness->writer()->release($name, $token);

                return [
                    'lease' => $this->leaseTrace($released),
                    'count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            [
                'lease' => [
                    'state' => 'R',
                    'epoch' => 1,
                    'targets' => [1],
                ],
                'count' => 0,
            ],
        );
    }

    public function test_p14_release_prepared_lease_decrements_once(): void
    {
        $this->assertParity(
            'P14',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1]),
                );
                $token = $this->token('2');
                $harness->writer()->acquire($name, $token);
                $harness->writer()->markPrepared($name, $token);
                $released = $harness->writer()->release($name, $token);

                return [
                    'lease' => $this->leaseTrace($released),
                    'count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            [
                'lease' => [
                    'state' => 'R',
                    'epoch' => 1,
                    'targets' => [1],
                ],
                'count' => 0,
            ],
        );
    }

    public function test_p15_release_retry_is_terminal_and_count_neutral(): void
    {
        $this->assertParity(
            'P15',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1]),
                );
                $token = $this->token('4');
                $harness->writer()->acquire($name, $token);
                $harness->writer()->markPrepared($name, $token);
                $first = $harness->writer()->release($name, $token);
                $retry = $harness->writer()->release($name, $token);

                return [
                    'first' => $this->leaseTrace($first),
                    'retry' => $this->leaseTrace($retry),
                    'count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            [
                'first' => [
                    'state' => 'R',
                    'epoch' => 1,
                    'targets' => [1],
                ],
                'retry' => [
                    'state' => 'R',
                    'epoch' => 1,
                    'targets' => [1],
                ],
                'count' => 0,
            ],
        );
    }

    public function test_p16_epoch_rotation_yields_only_complete_old_or_new_bindings(): void
    {
        $this->assertParity(
            'P16',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1, 2]),
                );

                $old = $harness->writer()->acquire($name, $this->token('5'));

                $harness->lifecycle()->compareAndSwapSynchronization(
                    $name,
                    $this->syncState(2, 2, [2, 3]),
                    SynchronizationRevision::fromInt(1),
                    null,
                );

                $new = $harness->writer()->acquire($name, $this->token('6'));

                return [
                    'old' => $this->leaseTrace($old),
                    'new' => $this->leaseTrace($new),
                    'old_count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                    'new_count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(2),
                    ),
                ];
            },
            [
                'old' => [
                    'state' => 'A',
                    'epoch' => 1,
                    'targets' => [1, 2],
                ],
                'new' => [
                    'state' => 'A',
                    'epoch' => 2,
                    'targets' => [2, 3],
                ],
                'old_count' => 1,
                'new_count' => 1,
            ],
        );
    }

    public function test_p17_malformed_sync_lease_and_count_share_corruption_classification(): void
    {
        $this->assertParity(
            'P17',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $sync = FilterName::fromString($name->value().'.sync');
                $harness->putCoordination(
                    $sync,
                    true,
                    $this->syncState(1, 1, [1]),
                );
                $harness->corruptSynchronization($sync);

                $lease = FilterName::fromString($name->value().'.lease');
                $harness->putCoordination(
                    $lease,
                    true,
                    $this->syncState(1, 1, [1]),
                );
                $leaseToken = $this->token('7');
                $harness->corruptLease($lease, $leaseToken);

                $count = FilterName::fromString($name->value().'.count');
                $harness->putCoordination(
                    $count,
                    true,
                    $this->syncState(1, 1, [1]),
                );
                $harness->corruptCount(
                    $count,
                    SynchronizationEpoch::fromInt(1),
                );

                return [
                    'sync' => $this->outcome(
                        fn () => $harness->writer()->read($sync),
                    ),
                    'lease' => $this->outcome(
                        fn () => $harness->writer()->release($lease, $leaseToken),
                    ),
                    'count' => $this->outcome(
                        fn () => $harness->writer()->activeWriterCount(
                            $count,
                            SynchronizationEpoch::fromInt(1),
                        ),
                    ),
                ];
            },
            [
                'sync' => 'corrupt',
                'lease' => 'corrupt',
                'count' => 'corrupt',
            ],
        );
    }

    public function test_p18_staging_residue_is_never_current_correctness_state(): void
    {
        $this->assertParity(
            'P18',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1]),
                );
                $harness->putStagingSynchronization(
                    $name,
                    $this->syncState(99, 9, [9]),
                );

                $lifecycle = $harness->lifecycle()->read($name);
                $writer = $harness->writer()->read($name);

                return [
                    'lifecycle_revision' => $lifecycle->synchronization()?->revision()->value(),
                    'lifecycle_epoch' => $lifecycle->synchronization()?->currentEpoch()->value(),
                    'writer_revision' => $writer?->revision()->value(),
                    'writer_epoch' => $writer?->currentEpoch()->value(),
                ];
            },
            [
                'lifecycle_revision' => 1,
                'lifecycle_epoch' => 1,
                'writer_revision' => 1,
                'writer_epoch' => 1,
            ],
        );
    }

    public function test_p19_zero_active_count_is_valid_drain_evidence(): void
    {
        $this->assertParity(
            'P19',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(2, 2, [2]),
                );

                return [
                    'old_epoch_count' => $harness->writer()->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                ];
            },
            ['old_epoch_count' => 0],
        );
    }

    public function test_p20_corrupt_active_count_does_not_prove_drain(): void
    {
        $this->assertParity(
            'P20',
            function (CoordinationParityHarness $harness, FilterName $name): array {
                $harness->putCoordination(
                    $name,
                    true,
                    $this->syncState(1, 1, [1]),
                );
                $harness->corruptCount(
                    $name,
                    SynchronizationEpoch::fromInt(1),
                );

                return [
                    'outcome' => $this->outcome(
                        fn () => $harness->writer()->activeWriterCount(
                            $name,
                            SynchronizationEpoch::fromInt(1),
                        ),
                    ),
                ];
            },
            ['outcome' => 'corrupt'],
        );
    }

    /**
     * @param  callable(CoordinationParityHarness, FilterName): array<string, mixed>  $scenario
     * @param  array<string, mixed>  $expected
     */
    private function assertParity(
        string $id,
        callable $scenario,
        array $expected,
    ): void {
        $memory = new MemoryCoordinationParityHarness;
        $redis = new RedisCoordinationParityHarness;
        $name = FilterName::fromString('parity.'.strtolower($id));

        try {
            $memoryTrace = $scenario($memory, $name);
            $redisTrace = $scenario($redis, $name);

            self::assertSame(
                $expected,
                $memoryTrace,
                sprintf('%s Memory reference trace drifted.', $id),
            );
            self::assertSame(
                $memoryTrace,
                $redisTrace,
                sprintf('%s Redis trace diverged from Memory.', $id),
            );
        } finally {
            $memory->cleanup();
            $redis->cleanup();
        }
    }

    private function outcome(callable $operation): string
    {
        try {
            $operation();

            return 'ok';
        } catch (CoordinationFenced) {
            return 'fenced';
        } catch (CoordinationStateCorrupt) {
            return 'corrupt';
        } catch (CoordinationWriteConflict) {
            return 'conflict';
        } catch (FilterControlWriteConflict) {
            return 'control_conflict';
        } catch (UnknownWriterLease) {
            return 'unknown_lease';
        } catch (WriterLeaseReleased) {
            return 'released_lease';
        } catch (Throwable $failure) {
            throw new RuntimeException(
                sprintf(
                    'Unexpected parity operation failure [%s]: %s',
                    $failure::class,
                    $failure->getMessage(),
                ),
                0,
                $failure,
            );
        }
    }

    /**
     * @return array{
     *     owner: bool,
     *     control_revision: ?int,
     *     sync_revision: ?int,
     *     epoch: ?int,
     *     targets: list<int>
     * }
     */
    private function snapshotTrace(
        CoordinatedLifecycleSnapshot $snapshot,
    ): array {
        $synchronization = $snapshot->synchronization();

        return [
            'owner' => $snapshot->ownershipClaimed(),
            'control_revision' => $snapshot->control()?->revision()->value(),
            'sync_revision' => $synchronization?->revision()->value(),
            'epoch' => $synchronization?->currentEpoch()->value(),
            'targets' => $synchronization === null
                ? []
                : $this->targetValues($synchronization->currentTargets()),
        ];
    }

    /**
     * @return array{state: string, epoch: int, targets: list<int>}
     */
    private function leaseTrace(WriterLease $lease): array
    {
        return [
            'state' => match ($lease->state()) {
                WriterLeaseState::Acquired => 'A',
                WriterLeaseState::Prepared => 'P',
                WriterLeaseState::Released => 'R',
            },
            'epoch' => $lease->epoch()->value(),
            'targets' => $this->targetValues($lease->targets()),
        ];
    }

    private function controlState(
        FilterName $name,
        int $revision,
    ): FilterControlState {
        $version = FilterVersion::fromInt(1);

        return new FilterControlState(
            filterName: $name,
            revision: FilterStateRevision::fromInt($revision),
            lastAllocatedVersion: $version,
            activeVersion: null,
            candidateVersion: $version,
            generations: [
                new GenerationControlState(
                    version: $version,
                    lifecycle: LifecycleState::Configured,
                    health: HealthState::Unavailable,
                ),
            ],
        );
    }

    /**
     * @param  list<int>  $targets
     */
    private function syncState(
        int $revision,
        int $epoch,
        array $targets,
    ): SynchronizationState {
        return new SynchronizationState(
            revision: SynchronizationRevision::fromInt($revision),
            phase: SynchronizationPhase::Steady,
            currentEpoch: SynchronizationEpoch::fromInt($epoch),
            currentTargets: SynchronizationTargetSet::fromVersions(array_map(
                static fn (int $version): FilterVersion => FilterVersion::fromInt($version),
                $targets,
            )),
            candidateVersion: null,
            drainingEpoch: null,
        );
    }

    private function token(string $character): WriterLeaseToken
    {
        return WriterLeaseToken::fromString(str_repeat($character, 32));
    }

    /**
     * @return list<int>
     */
    private function targetValues(
        SynchronizationTargetSet $targets,
    ): array {
        return array_map(
            static fn (FilterVersion $version): int => $version->value(),
            $targets->versions(),
        );
    }
}
