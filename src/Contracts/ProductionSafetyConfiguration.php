<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\ProductionSafetySettings;

interface ProductionSafetyConfiguration
{
    public function resolve(): ProductionSafetySettings;
}
