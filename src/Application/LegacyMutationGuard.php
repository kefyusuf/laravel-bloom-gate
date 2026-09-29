<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Core\FilterName;

final readonly class LegacyMutationGuard
{
    public function __construct(
        private CoordinatedLifecycleStore $coordination,
        private RuntimeCoordinationRequirement $runtime,
    ) {}

    public function assertAllowed(FilterName $name): void
    {
        $snapshot = $this->coordination->read($name);

        if ($snapshot->ownershipClaimed()) {
            throw new CoordinationFenced(
                'Legacy managed mutation is fenced after coordinated ownership begins.',
            );
        }

        if ($this->runtime->requiresCoordinatedV1($name)) {
            throw new CoordinationFenced(
                'Legacy managed mutation is fenced because this runtime requires coordinated-v1.',
            );
        }
    }
}
