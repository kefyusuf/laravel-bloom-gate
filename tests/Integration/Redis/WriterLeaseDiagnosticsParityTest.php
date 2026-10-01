<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\WriterLeaseInspector;
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
use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryWriterSynchronizationContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisWriterSynchronizationContractFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('redis')]
final class WriterLeaseDiagnosticsParityTest extends TestCase
{
    public function test_memory_and_redis_enumerate_only_active_acquired_and_prepared_leases(): void
    {
        $memory = $this->scenario(
            new MemoryWriterSynchronizationContractFixture,
            'memory',
        );
        $redis = $this->scenario(
            new RedisWriterSynchronizationContractFixture,
            'redis',
        );

        self::assertSame($memory, $redis);
        self::assertSame(
            [
                ['token' => str_repeat('a', 32), 'state' => 'A', 'epoch' => 1, 'targets' => [1]],
                ['token' => str_repeat('b', 32), 'state' => 'P', 'epoch' => 1, 'targets' => [1]],
            ],
            $memory,
        );
    }

    public function test_memory_and_redis_reject_count_lease_diagnostic_disagreement(): void
    {
        foreach ([
            new MemoryWriterSynchronizationContractFixture,
            new RedisWriterSynchronizationContractFixture,
        ] as $index => $fixture) {
            $name = FilterName::fromString(
                'wu10.lease.mismatch.'.($index === 0 ? 'memory' : 'redis'),
            );
            $fixture->putCoordination(
                $name,
                true,
                new SynchronizationState(
                    revision: SynchronizationRevision::fromInt(1),
                    phase: SynchronizationPhase::Steady,
                    currentEpoch: SynchronizationEpoch::fromInt(1),
                    currentTargets: SynchronizationTargetSet::fromVersions([
                        FilterVersion::fromInt(1),
                    ]),
                    candidateVersion: null,
                    drainingEpoch: null,
                ),
            );
            $fixture->setActiveWriterCount(
                $name,
                SynchronizationEpoch::fromInt(1),
                1,
            );

            $store = $fixture->store();
            self::assertInstanceOf(WriterLeaseInspector::class, $store);

            try {
                $store->activeLeases($name);
                self::fail('Expected diagnostic count/lease disagreement to fail closed.');
            } catch (CoordinationStateCorrupt) {
                self::assertSame(
                    1,
                    $store->activeWriterCount(
                        $name,
                        SynchronizationEpoch::fromInt(1),
                    ),
                );
            }
        }
    }

    /**
     * @return list<array{
     *     token: string,
     *     state: string,
     *     epoch: int,
     *     targets: list<int>
     * }>
     */
    private function scenario(
        WriterSynchronizationStoreContractFixture $fixture,
        string $suffix,
    ): array {
        $name = FilterName::fromString('wu10.lease.diagnostics.'.$suffix);
        $fixture->putCoordination(
            $name,
            true,
            new SynchronizationState(
                revision: SynchronizationRevision::fromInt(1),
                phase: SynchronizationPhase::Steady,
                currentEpoch: SynchronizationEpoch::fromInt(1),
                currentTargets: SynchronizationTargetSet::fromVersions([
                    FilterVersion::fromInt(1),
                ]),
                candidateVersion: null,
                drainingEpoch: null,
            ),
        );

        $store = $fixture->store();
        self::assertInstanceOf(WriterLeaseInspector::class, $store);

        $acquired = WriterLeaseToken::fromString(str_repeat('a', 32));
        $prepared = WriterLeaseToken::fromString(str_repeat('b', 32));
        $released = WriterLeaseToken::fromString(str_repeat('c', 32));

        $store->acquire($name, $acquired);
        $store->acquire($name, $prepared);
        $store->markPrepared($name, $prepared);
        $store->acquire($name, $released);
        $store->release($name, $released);

        return array_map(
            fn (WriterLease $lease): array => [
                'token' => $lease->token()->value(),
                'state' => $this->state($lease->state()),
                'epoch' => $lease->epoch()->value(),
                'targets' => array_map(
                    static fn (FilterVersion $version): int => $version->value(),
                    $lease->targets()->versions(),
                ),
            ],
            $store->activeLeases($name),
        );
    }

    private function state(WriterLeaseState $state): string
    {
        return match ($state) {
            WriterLeaseState::Acquired => 'A',
            WriterLeaseState::Prepared => 'P',
            WriterLeaseState::Released => 'R',
        };
    }
}
