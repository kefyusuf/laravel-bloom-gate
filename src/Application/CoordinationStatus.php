<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLease;

final readonly class CoordinationStatus
{
    /**
     * @param  list<WriterLease>  $activeLeases
     */
    public function __construct(
        private CoordinationOwnership $ownership,
        private bool $runtimeRequiresCoordination,
        private ?SynchronizationRevision $synchronizationRevision,
        private ?SynchronizationPhase $phase,
        private ?SynchronizationEpoch $currentEpoch,
        private ?SynchronizationTargetSet $currentTargets,
        private ?FilterVersion $candidateVersion,
        private ?SynchronizationEpoch $drainingEpoch,
        private ?int $drainingActiveWriterCount,
        private CoordinationBlocker $blocker,
        private CoordinationRecovery $recovery,
        private bool $leasesIncluded = false,
        private array $activeLeases = [],
    ) {}

    public function ownership(): CoordinationOwnership
    {
        return $this->ownership;
    }

    public function runtimeRequiresCoordination(): bool
    {
        return $this->runtimeRequiresCoordination;
    }

    public function synchronizationRevision(): ?SynchronizationRevision
    {
        return $this->synchronizationRevision;
    }

    public function phase(): ?SynchronizationPhase
    {
        return $this->phase;
    }

    public function currentEpoch(): ?SynchronizationEpoch
    {
        return $this->currentEpoch;
    }

    public function currentTargets(): ?SynchronizationTargetSet
    {
        return $this->currentTargets;
    }

    public function candidateVersion(): ?FilterVersion
    {
        return $this->candidateVersion;
    }

    public function drainingEpoch(): ?SynchronizationEpoch
    {
        return $this->drainingEpoch;
    }

    public function drainingActiveWriterCount(): ?int
    {
        return $this->drainingActiveWriterCount;
    }

    public function blocker(): CoordinationBlocker
    {
        return $this->blocker;
    }

    public function recovery(): CoordinationRecovery
    {
        return $this->recovery;
    }

    public function leasesIncluded(): bool
    {
        return $this->leasesIncluded;
    }

    /**
     * @return list<WriterLease>
     */
    public function activeLeases(): array
    {
        return $this->activeLeases;
    }
}
