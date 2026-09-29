<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Memory;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Tests\Contract\Support\CoordinatedLifecycleStoreContractFixture;

final class MemoryCoordinatedLifecycleContractFixture implements CoordinatedLifecycleStoreContractFixture
{
    private MemoryCoordinationFixtureState $state;

    public function __construct()
    {
        $this->state = new MemoryCoordinationFixtureState;
    }

    public function store(): CoordinatedLifecycleStore
    {
        return $this->state->lifecycle;
    }

    public function putControl(
        FilterName $name,
        ?FilterControlState $control,
    ): void {
        $this->state->putControl($name, $control);
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

    public function putStagingSynchronization(
        FilterName $name,
        SynchronizationState $synchronization,
    ): void {
        // Memory has no staging correctness plane. The fixture deliberately
        // keeps staging outside the shared domain.
    }
}
