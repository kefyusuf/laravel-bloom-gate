<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Memory;

use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use ReflectionProperty;

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

    public function removeActiveWriterCount(FilterName $name, ?SynchronizationEpoch $epoch): void
    {
        $property = new ReflectionProperty(MemoryCoordinationDomain::class, 'counts');
        /** @var array<string, array<int, mixed>> $counts */
        $counts = $property->getValue($this->state->domain);
        if ($epoch === null) {
            unset($counts[$name->value()]);
        } else {
            unset($counts[$name->value()][$epoch->value()]);
        }
        $property->setValue($this->state->domain, $counts);
    }

    public function corruptActiveWriterCount(FilterName $name, SynchronizationEpoch $epoch): void
    {
        $this->state->setActiveWriterCount($name, $epoch, -1);
    }

    public function corruptLease(FilterName $name, WriterLeaseToken $token): void
    {
        $property = new ReflectionProperty(MemoryCoordinationDomain::class, 'leases');
        /** @var array<string, array<string, mixed>> $leases */
        $leases = $property->getValue($this->state->domain);
        $leases[$name->value()][$token->value()] = 'invalid-lease';
        $property->setValue($this->state->domain, $leases);
    }

    public function rawWriterState(FilterName $name): array
    {
        $result = [];
        foreach (['counts', 'leases'] as $field) {
            /** @var array<string, mixed> $entries */
            $entries = (new ReflectionProperty(MemoryCoordinationDomain::class, $field))
                ->getValue($this->state->domain);
            $result[$field] = $entries[$name->value()] ?? [];
        }

        return $result;
    }
}
