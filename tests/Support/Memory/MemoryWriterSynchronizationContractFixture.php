<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Memory;

use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;

final class MemoryWriterSynchronizationContractFixture implements WriterSynchronizationStoreContractFixture
{
    private MemoryCoordinationFixtureState $state;

    public function __construct()
    {
        $this->state = new MemoryCoordinationFixtureState;
    }

    public function store(): WriterSynchronizationStore
    {
        return $this->state->writer;
    }

    public function putCoordination(
        FilterName $name,
        bool $ownershipClaimed,
        ?SynchronizationState $synchronization,
    ): void {
        $this->state->putCoordination(
            $name,
            $ownershipClaimed,
            $synchronization,
        );
    }

    public function setActiveWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
        int $count,
    ): void {
        $this->state->setActiveWriterCount($name, $epoch, $count);
    }
}
