<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\FilterName;

interface RuntimeCoordinationRequirement
{
    public function requiresCoordinatedV1(FilterName $name): bool;
}
