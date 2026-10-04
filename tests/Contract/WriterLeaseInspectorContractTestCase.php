<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Application\CoordinatedFilterAdopter;
use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Application\CoordinationStatusState;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Contracts\WriterLeaseInspector;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use PHPUnit\Framework\TestCase;

abstract class WriterLeaseInspectorContractTestCase extends TestCase
{
    /** @return array{CoordinatedLifecycleStore, WriterSynchronizationStore&WriterLeaseInspector} */
    abstract protected function stores(): array;

    public function test_optional_lease_diagnostics_keep_original_bindings_and_exclude_released_tokens(): void
    {
        [$lifecycle, $writers] = $this->stores();
        $name = FilterName::fromString('users.email');
        self::assertSame([], $writers->readActiveLeases($name));
        (new CoordinatedFilterAdopter($lifecycle))->adopt($name);
        $acquired = WriterLeaseToken::fromString(str_repeat('a', 32));
        $prepared = WriterLeaseToken::fromString(str_repeat('b', 32));
        $released = WriterLeaseToken::fromString(str_repeat('c', 32));
        $writers->acquire($name, $prepared);
        $writers->markPrepared($name, $prepared);
        $writers->acquire($name, $acquired);
        $writers->acquire($name, $released);
        $writers->release($name, $released);
        $lifecycle->compareAndSwapSynchronization($name, new SynchronizationState(
            SynchronizationRevision::fromInt(2), SynchronizationPhase::Steady,
            SynchronizationEpoch::fromInt(2), SynchronizationTargetSet::fromVersions([]), null, null,
        ), SynchronizationRevision::fromInt(1), null);
        $before = $lifecycle->read($name);
        $leases = $writers->readActiveLeases($name);

        self::assertCount(2, $leases);
        self::assertTrue($leases[0]->token()->equals($acquired));
        self::assertSame(WriterLeaseState::Acquired, $leases[0]->state());
        self::assertSame(WriterLeaseState::Prepared, $leases[1]->state());
        self::assertSame(1, $leases[0]->epoch()->value());
        self::assertSame(1, $leases[1]->epoch()->value());
        self::assertTrue($leases[0]->targets()->equals(SynchronizationTargetSet::fromVersions([])));
        self::assertSame(2, $writers->activeWriterCount($name, SynchronizationEpoch::fromInt(1)));
        self::assertSame(WriterLeaseState::Released, $writers->readLease($name, $released)?->state());
        self::assertEquals($before, $lifecycle->read($name));

        $requirement = $this->createMock(RuntimeCoordinationRequirement::class);
        $requirement->method('requiresCoordinatedV1')->willReturn(true);
        $reader = new CoordinationStatusReader($lifecycle, $writers, $requirement, $writers);
        self::assertSame([], $reader->read($name)->leases());
        $status = $reader->read($name, includeLeases: true);
        self::assertSame(CoordinationStatusState::Adopted, $status->state());
        self::assertEquals($leases, $status->leases());
        self::assertEquals($before, $lifecycle->read($name));
    }
}
