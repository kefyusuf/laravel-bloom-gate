<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

/** Models an external writer completing between two polling observations. */
final class DrainObservingWriterSynchronizationStore implements WriterSynchronizationStore
{
    private int $countReads = 0;

    public function __construct(
        private WriterSynchronizationStore $inner,
        private FilterName $filter,
        private WriterLeaseToken $token,
    ) {}

    public function read(FilterName $name): ?SynchronizationState
    {
        return $this->inner->read($name);
    }

    public function readLease(FilterName $name, WriterLeaseToken $token): ?WriterLease
    {
        return $this->inner->readLease($name, $token);
    }

    public function acquire(FilterName $name, WriterLeaseToken $token): WriterLease
    {
        return $this->inner->acquire($name, $token);
    }

    public function markPrepared(FilterName $name, WriterLeaseToken $token): WriterLease
    {
        return $this->inner->markPrepared($name, $token);
    }

    public function release(FilterName $name, WriterLeaseToken $token): WriterLease
    {
        return $this->inner->release($name, $token);
    }

    public function activeWriterCount(FilterName $name, SynchronizationEpoch $epoch): int
    {
        if (++$this->countReads === 2) {
            $this->inner->release($this->filter, $this->token);
        }

        return $this->inner->activeWriterCount($name, $epoch);
    }
}
