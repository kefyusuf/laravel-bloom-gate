<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\WriterLeaseInspector;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryWriterSynchronizationContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisWriterSynchronizationContractFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('redis')]
final class WriterLeaseCountDiagnosticsParityTest extends TestCase
{
    /** @return iterable<string, array{int, int, int}> */
    public static function inconsistentCounts(): iterable
    {
        yield 'positive count without leases' => [0, 1, 1];
        yield 'active lease with zero count' => [1, 0, 1];
        yield 'active lease with excess count' => [1, 2, 1];
        yield 'orphan count in another epoch' => [1, 1, 2];
    }

    #[DataProvider('inconsistentCounts')]
    public function test_inconsistent_enumeration_fails_without_repair(int $leaseCount, int $count, int $epoch): void
    {
        foreach ($this->fixtures() as $fixture) {
            $name = FilterName::fromString('diagnostics.counts');
            $this->seed($fixture, $name);
            $store = $fixture->store();
            self::assertInstanceOf(WriterLeaseInspector::class, $store);
            $token = WriterLeaseToken::fromString(str_repeat('a', 32));
            if ($leaseCount > 0) {
                $store->acquire($name, $token);
                $store->markPrepared($name, $token);
            }
            $fixture->setActiveWriterCount($name, SynchronizationEpoch::fromInt($epoch), $count);
            $before = $store->readLease($name, $token);
            $rawBefore = $fixture->rawWriterState($name);

            try {
                $store->readActiveLeases($name);
                self::fail('Count/lease disagreement must not produce valid diagnostics.');
            } catch (CoordinationStateCorrupt) {
                self::assertEquals($before, $store->readLease($name, $token));
                self::assertEquals($rawBefore, $fixture->rawWriterState($name));
            }
        }
    }

    public function test_released_tombstones_with_zero_counts_are_valid_and_preserved(): void
    {
        foreach ($this->fixtures() as $fixture) {
            $name = FilterName::fromString('diagnostics.released');
            $this->seed($fixture, $name);
            $store = $fixture->store();
            self::assertInstanceOf(WriterLeaseInspector::class, $store);
            $token = WriterLeaseToken::fromString(str_repeat('b', 32));
            $store->acquire($name, $token);
            $store->release($name, $token);
            $before = $store->readLease($name, $token);

            self::assertSame([], $store->readActiveLeases($name));
            self::assertEquals($before, $store->readLease($name, $token));
            self::assertSame(0, $store->activeWriterCount($name, SynchronizationEpoch::fromInt(1)));
        }
    }

    public function test_zero_counts_without_lease_registry_are_valid(): void
    {
        foreach ($this->fixtures() as $fixture) {
            $name = FilterName::fromString('diagnostics.empty');
            $fixture->setActiveWriterCount($name, SynchronizationEpoch::fromInt(1), 0);
            $store = $fixture->store();
            self::assertInstanceOf(WriterLeaseInspector::class, $store);
            self::assertSame([], $store->readActiveLeases($name));
        }
    }

    /** @return list<WriterSynchronizationStoreContractFixture> */
    private function fixtures(): array
    {
        return [new MemoryWriterSynchronizationContractFixture, new RedisWriterSynchronizationContractFixture];
    }

    private function seed(WriterSynchronizationStoreContractFixture $fixture, FilterName $name): void
    {
        $fixture->putCoordination($name, true, new SynchronizationState(
            SynchronizationRevision::fromInt(1), SynchronizationPhase::Steady,
            SynchronizationEpoch::fromInt(1), SynchronizationTargetSet::fromVersions([]), null, null,
        ));
    }
}
