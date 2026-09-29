<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract\Support;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationState;

interface CoordinatedLifecycleStoreContractFixture
{
    public function store(): CoordinatedLifecycleStore;

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
}
