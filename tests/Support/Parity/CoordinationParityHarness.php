<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Parity;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

interface CoordinationParityHarness
{
    public function backend(): string;

    public function control(): FilterControlStore;

    public function lifecycle(): CoordinatedLifecycleStore;

    public function writer(): WriterSynchronizationStore;

    public function track(FilterName $name): void;

    public function putControl(
        FilterName $name,
        ?FilterControlState $control,
    ): void;

    public function putCoordination(
        FilterName $name,
        bool $ownershipClaimed,
        ?SynchronizationState $synchronization,
    ): void;

    public function putStagingSynchronization(
        FilterName $name,
        SynchronizationState $synchronization,
    ): void;

    public function setActiveWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
        int $count,
    ): void;

    public function corruptSynchronization(FilterName $name): void;

    public function corruptLease(
        FilterName $name,
        WriterLeaseToken $token,
    ): void;

    public function corruptCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): void;

    public function cleanup(): void;
}
