<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use Kefyusuf\BloomGate\Contracts\WriterLeaseInspector;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

final readonly class MemoryWriterSynchronizationStore implements WriterLeaseInspector, WriterSynchronizationStore
{
    public function __construct(
        private MemoryCoordinationDomain $domain,
    ) {}

    public function read(FilterName $name): ?SynchronizationState
    {
        return $this->domain->readSynchronization($name);
    }

    public function readActiveLeases(FilterName $name): array
    {
        return $this->domain->readActiveLeases($name);
    }

    public function readLease(
        FilterName $name,
        WriterLeaseToken $token,
    ): ?WriterLease {
        return $this->domain->readLease($name, $token);
    }

    public function acquire(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        return $this->domain->acquire($name, $token);
    }

    public function markPrepared(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        return $this->domain->markPrepared($name, $token);
    }

    public function release(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        return $this->domain->release($name, $token);
    }

    public function activeWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): int {
        return $this->domain->activeWriterCount($name, $epoch);
    }
}
