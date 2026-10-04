<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

/** Simulates lost acknowledgements after a real persistence operation. */
final class InterruptingWriterSynchronizationStore implements WriterSynchronizationStore
{
    public function __construct(
        private WriterSynchronizationStore $inner,
        public ?string $interruptAfter = null,
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
        $lease = $this->inner->acquire($name, $token);
        $this->interrupt('acquire');

        return $lease;
    }

    public function markPrepared(FilterName $name, WriterLeaseToken $token): WriterLease
    {
        $lease = $this->inner->markPrepared($name, $token);
        $this->interrupt('prepare');

        return $lease;
    }

    public function release(FilterName $name, WriterLeaseToken $token): WriterLease
    {
        $lease = $this->inner->release($name, $token);
        $this->interrupt('release');

        return $lease;
    }

    public function activeWriterCount(FilterName $name, SynchronizationEpoch $epoch): int
    {
        return $this->inner->activeWriterCount($name, $epoch);
    }

    private function interrupt(string $operation): void
    {
        if ($this->interruptAfter === $operation) {
            $this->interruptAfter = null;

            throw new CoordinationStoreOperationFailed('Simulated lost '.$operation.' acknowledgement.');
        }
    }
}
