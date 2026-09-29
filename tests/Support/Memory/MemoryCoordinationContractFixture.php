<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Memory;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationStore;
use Kefyusuf\BloomGate\Tests\Contract\Support\CoordinatedLifecycleStoreContractFixture;
use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use ReflectionProperty;

final class MemoryCoordinationContractFixture implements
    CoordinatedLifecycleStoreContractFixture,
    WriterSynchronizationStoreContractFixture
{
    private MemoryCoordinationStore $store;

    public function __construct()
    {
        $this->store = new MemoryCoordinationStore;
    }

    public function store(): CoordinatedLifecycleStore&WriterSynchronizationStore
    {
        return $this->store;
    }

    public function putControl(
        FilterName $name,
        ?FilterControlState $control,
    ): void {
        $this->replaceEntry(
            property: 'controls',
            name: $name,
            value: $control,
        );
    }

    public function putCoordination(
        FilterName $name,
        bool $ownershipClaimed,
        ?SynchronizationState $synchronization,
    ): void {
        $this->replaceEntry(
            property: 'owners',
            name: $name,
            value: $ownershipClaimed ? true : null,
        );
        $this->replaceEntry(
            property: 'synchronizations',
            name: $name,
            value: $synchronization,
        );
    }

    public function putStagingSynchronization(
        FilterName $name,
        SynchronizationState $synchronization,
    ): void {
        // Memory has no staging correctness plane. The fixture deliberately
        // keeps staging outside the store so current-state reads cannot observe it.
    }

    public function setActiveWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
        int $count,
    ): void {
        $property = new ReflectionProperty(
            MemoryCoordinationStore::class,
            'counts',
        );
        $counts = $property->getValue($this->store);
        $nameKey = $name->value();
        $epochKey = $epoch->value();

        $counts[$nameKey][$epochKey] = $count;

        $property->setValue($this->store, $counts);
    }

    private function replaceEntry(
        string $property,
        FilterName $name,
        mixed $value,
    ): void {
        $reflection = new ReflectionProperty(
            MemoryCoordinationStore::class,
            $property,
        );
        $entries = $reflection->getValue($this->store);
        $key = $name->value();

        if ($value === null) {
            unset($entries[$key]);
        } else {
            $entries[$key] = $value;
        }

        $reflection->setValue($this->store, $entries);
    }
}
