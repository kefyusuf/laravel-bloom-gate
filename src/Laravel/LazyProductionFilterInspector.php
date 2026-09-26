<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Kefyusuf\BloomGate\Application\ManagedFilterStatusReader;
use Kefyusuf\BloomGate\Contracts\ProductionFilterInspector;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\ProductionFilterRuntimeStatus;

final readonly class LazyProductionFilterInspector implements ProductionFilterInspector
{
    public function __construct(
        private Application $app,
    ) {}

    public function inspect(FilterName $name): ProductionFilterRuntimeStatus
    {
        $status = $this->app
            ->make(ManagedFilterStatusReader::class)
            ->read($name);

        $active = $status->active();

        if ($active === null) {
            return ProductionFilterRuntimeStatus::withoutActiveGeneration();
        }

        return ProductionFilterRuntimeStatus::active(
            lifecycle: $active->lifecycle(),
            health: $active->health(),
            layoutAvailable: $active->layout() !== null,
            semanticBound: $active->semanticBound(),
            semanticMatches: $active->semanticMatches(),
        );
    }
}
