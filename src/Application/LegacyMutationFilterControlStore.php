<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;

final readonly class LegacyMutationFilterControlStore implements ActiveGenerationSnapshotReader, FilterControlStore
{
    public function __construct(
        private FilterControlStore $store,
        private ActiveGenerationSnapshotReader $snapshots,
        private LegacyMutationGuard $guard,
    ) {}

    public function read(FilterName $name): ?FilterControlState
    {
        return $this->store->read($name);
    }

    public function readActive(FilterName $name): ?ActiveGenerationSnapshot
    {
        return $this->snapshots->readActive($name);
    }

    public function compareAndSwap(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void {
        $this->guard->assertAllowed($name);

        $this->store->compareAndSwap(
            $name,
            $next,
            $expectedRevision,
        );
    }
}
