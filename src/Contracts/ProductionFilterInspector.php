<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\ProductionFilterRuntimeStatus;

interface ProductionFilterInspector
{
    public function inspect(FilterName $name): ProductionFilterRuntimeStatus;
}
