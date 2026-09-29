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
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryCoordinationFixtureState;
use ReflectionProperty;

final class MemoryCoordinationParityHarness implements CoordinationParityHarness
{
    private MemoryCoordinationFixtureState $state;

    public function __construct()
    {
        $this->state = new MemoryCoordinationFixtureState;
    }

    public function backend(): string
    {
        return 'memory';
    }

    public function control(): FilterControlStore
    {
        return $this->state->control;
    }

    public function lifecycle(): CoordinatedLifecycleStore
    {
        return $this->state->lifecycle;
    }

    public function writer(): WriterSynchronizationStore
    {
        return $this->state->writer;
    }

    public function track(FilterName $name): void {}

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
        // Memory has no staging correctness plane by design.
    }

    public function setActiveWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
        int $count,
    ): void {
        $this->state->setActiveWriterCount($name, $epoch, $count);
    }

    public function corruptSynchronization(FilterName $name): void
    {
        $this->replaceDomainEntry(
            'synchronizations',
            $name->value(),
            'invalid-sync',
        );
    }

    public function corruptLease(
        FilterName $name,
        WriterLeaseToken $token,
    ): void {
        $property = new ReflectionProperty(
            MemoryCoordinationDomain::class,
            'leases',
        );
        /** @var array<string, array<string, mixed>> $leases */
        $leases = $property->getValue($this->state->domain);
        $leases[$name->value()][$token->value()] = 'invalid-lease';
        $property->setValue($this->state->domain, $leases);
    }

    public function corruptCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): void {
        $property = new ReflectionProperty(
            MemoryCoordinationDomain::class,
            'counts',
        );
        /** @var array<string, array<int, mixed>> $counts */
        $counts = $property->getValue($this->state->domain);
        $counts[$name->value()][$epoch->value()] = -1;
        $property->setValue($this->state->domain, $counts);
    }

    public function cleanup(): void {}

    private function replaceDomainEntry(
        string $propertyName,
        string $key,
        mixed $value,
    ): void {
        $property = new ReflectionProperty(
            MemoryCoordinationDomain::class,
            $propertyName,
        );
        /** @var array<string, mixed> $entries */
        $entries = $property->getValue($this->state->domain);
        $entries[$key] = $value;
        $property->setValue($this->state->domain, $entries);
    }
}
