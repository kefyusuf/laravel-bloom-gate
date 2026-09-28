<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class WriterLease
{
    public function __construct(
        private WriterLeaseToken $token,
        private WriterLeaseState $state,
        private SynchronizationEpoch $epoch,
        private SynchronizationTargetSet $targets,
    ) {}

    public function token(): WriterLeaseToken
    {
        return $this->token;
    }

    public function state(): WriterLeaseState
    {
        return $this->state;
    }

    public function epoch(): SynchronizationEpoch
    {
        return $this->epoch;
    }

    public function targets(): SynchronizationTargetSet
    {
        return $this->targets;
    }
}
