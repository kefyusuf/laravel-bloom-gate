<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

interface WriterSynchronizationStore
{
    public function read(FilterName $name): ?SynchronizationState;

    public function acquire(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease;

    public function markPrepared(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease;

    public function release(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease;

    public function activeWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): int;

}
