<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\AuthoritativeOutcome;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

final readonly class CoordinatedLeaseRecovery
{
    public function __construct(
        private WriterSynchronizationStore $synchronization,
    ) {}

    public function resolve(
        FilterName $name,
        WriterLeaseToken $token,
        AuthoritativeOutcome $outcome,
    ): LeaseResolutionResult {
        if ($outcome === AuthoritativeOutcome::Unknown) {
            throw new InvalidArgumentException(
                'Unknown authoritative outcome cannot resolve a coordinated writer lease.',
            );
        }

        $lease = $this->synchronization->readLease($name, $token);

        if ($lease === null) {
            throw new UnknownWriterLease(
                'Writer lease token is unknown for this filter.',
            );
        }

        if ($lease->state() === WriterLeaseState::Released) {
            return LeaseResolutionResult::AlreadyReleased;
        }

        if (
            $outcome === AuthoritativeOutcome::Committed
            && $lease->state() !== WriterLeaseState::Prepared
        ) {
            throw new LeaseResolutionEvidenceInsufficient(
                'Committed authoritative outcome requires durable PREPARED lease evidence.',
            );
        }

        $this->synchronization->release($name, $token);

        return LeaseResolutionResult::Released;
    }
}
