<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Memory;

use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryWriterSynchronizationStore;
use ReflectionProperty;

final class MemoryCoordinationFixtureState
{
    public readonly MemoryCoordinationDomain $domain;

    public readonly MemoryCoordinatedLifecycleStore $lifecycle;

    public readonly MemoryWriterSynchronizationStore $writer;

    public readonly MemoryFilterControlStore $control;

    public function __construct()
    {
        $this->domain = new MemoryCoordinationDomain;
        $this->lifecycle = new MemoryCoordinatedLifecycleStore($this->domain);
        $this->writer = new MemoryWriterSynchronizationStore($this->domain);
        $this->control = new MemoryFilterControlStore($this->domain);
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

    public function setActiveWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
        int $count,
    ): void {
        $property = new ReflectionProperty(
            MemoryCoordinationDomain::class,
            'counts',
        );
        /** @var array<string, array<int, mixed>> $counts */
        $counts = $property->getValue($this->domain);
        $nameKey = $name->value();
        $epochKey = $epoch->value();

        $counts[$nameKey][$epochKey] = $count;

        $property->setValue($this->domain, $counts);
    }

    private function replaceEntry(
        string $property,
        FilterName $name,
        mixed $value,
    ): void {
        $reflection = new ReflectionProperty(
            MemoryCoordinationDomain::class,
            $property,
        );
        /** @var array<string, mixed> $entries */
        $entries = $reflection->getValue($this->domain);
        $key = $name->value();

        if ($value === null) {
            unset($entries[$key]);
        } else {
            $entries[$key] = $value;
        }

        $reflection->setValue($this->domain, $entries);
    }
}
