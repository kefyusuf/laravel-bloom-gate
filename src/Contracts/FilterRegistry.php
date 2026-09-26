<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\FilterName;

interface FilterRegistry
{
    public function globalQueryOptimizationEnabled(): bool;

    public function get(FilterName $name): RegisteredFilter;
}
