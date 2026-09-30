<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use UnexpectedValueException;

final readonly class PreparedCoordinatedWrite
{
    public function __construct(
        private FilterName $filterName,
        private WriterLease $lease,
        private WriterSynchronizationStore $synchronization,
    ) {
        if ($this->lease->state() !== WriterLeaseState::Prepared) {
            throw new InvalidArgumentException(
                'Prepared coordinated write requires a durable PREPARED writer lease.',
            );
        }
    }

    public function filterName(): FilterName
    {
        return $this->filterName;
    }

    public function token(): WriterLeaseToken
    {
        return $this->lease->token();
    }

    public function lease(): WriterLease
    {
        return $this->lease;
    }

    public function authoritativeCommitted(): CoordinatedWriterCompletionResult
    {
        return $this->releaseAfterKnownOutcome();
    }

    public function authoritativeAborted(): CoordinatedWriterCompletionResult
    {
        return $this->releaseAfterKnownOutcome();
    }

    private function releaseAfterKnownOutcome(): CoordinatedWriterCompletionResult
    {
        try {
            $released = $this->synchronization->release(
                $this->filterName,
                $this->lease->token(),
            );
        } catch (CoordinationStoreOperationFailed|CoordinationStateCorrupt|UnknownWriterLease) {
            return CoordinatedWriterCompletionResult::CleanupUncertain;
        }

        if ($released->state() !== WriterLeaseState::Released) {
            throw new UnexpectedValueException(
                'Known authoritative completion did not return a terminal RELEASED writer lease.',
            );
        }

        return CoordinatedWriterCompletionResult::Released;
    }
}
