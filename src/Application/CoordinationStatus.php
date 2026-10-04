<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLease;

/** Point-in-time diagnostics; never mutation authorization or drain proof. */
final readonly class CoordinationStatus
{
    /** @param list<WriterLease> $leases */
    public function __construct(
        private CoordinationStatusState $state,
        private ?bool $runtimeCoordinationRequired,
        private ?SynchronizationState $synchronization = null,
        private ?int $drainingActiveWriterCount = null,
        private ?string $issue = null,
        private array $leases = [],
    ) {}

    public function state(): CoordinationStatusState
    {
        return $this->state;
    }

    public function runtimeCoordinationRequired(): ?bool
    {
        return $this->runtimeCoordinationRequired;
    }

    public function synchronization(): ?SynchronizationState
    {
        return $this->synchronization;
    }

    public function drainingActiveWriterCount(): ?int
    {
        return $this->drainingActiveWriterCount;
    }

    public function issue(): ?string
    {
        return $this->issue;
    }

    /** @return list<WriterLease> */
    public function leases(): array
    {
        return $this->leases;
    }
}
