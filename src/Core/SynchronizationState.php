<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class SynchronizationState
{
    public function __construct(
        private SynchronizationRevision $revision,
        private SynchronizationPhase $phase,
        private SynchronizationEpoch $currentEpoch,
        private SynchronizationTargetSet $currentTargets,
        private ?FilterVersion $candidateVersion,
        private ?SynchronizationEpoch $drainingEpoch,
    ) {}

    public function revision(): SynchronizationRevision
    {
        return $this->revision;
    }

    public function phase(): SynchronizationPhase
    {
        return $this->phase;
    }

    public function currentEpoch(): SynchronizationEpoch
    {
        return $this->currentEpoch;
    }

    public function currentTargets(): SynchronizationTargetSet
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
}
