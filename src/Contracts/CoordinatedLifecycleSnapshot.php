<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\SynchronizationState;

final readonly class CoordinatedLifecycleSnapshot
{
    public function __construct(
        private bool $ownershipClaimed,
        private ?FilterControlState $control,
        private ?SynchronizationState $synchronization,
    ) {
        if (! $this->ownershipClaimed && $this->synchronization !== null) {
            throw new InvalidArgumentException(
                'Synchronization state cannot exist without coordinated ownership.',
            );
        }
    }

    public function ownershipClaimed(): bool
    {
        return $this->ownershipClaimed;
    }

    public function control(): ?FilterControlState
    {
        return $this->control;
    }

    public function synchronization(): ?SynchronizationState
    {
        return $this->synchronization;
    }
}
