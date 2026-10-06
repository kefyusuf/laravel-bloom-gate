<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract\Support;

use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

interface WriterSynchronizationStoreContractFixture
{
    public function store(): WriterSynchronizationStore;

    public function putCoordination(
        FilterName $name,
        bool $ownershipClaimed,
        ?SynchronizationState $synchronization,
    ): void;

    public function setActiveWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
        int $count,
    ): void;

    public function removeActiveWriterCount(FilterName $name, ?SynchronizationEpoch $epoch): void;

    public function corruptActiveWriterCount(FilterName $name, SynchronizationEpoch $epoch): void;

    public function corruptLease(FilterName $name, WriterLeaseToken $token): void;

    /** @return array<string, mixed> */
    public function rawWriterState(FilterName $name): array;
}
