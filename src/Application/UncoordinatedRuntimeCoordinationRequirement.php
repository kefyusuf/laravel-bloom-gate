<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Core\FilterName;

final readonly class UncoordinatedRuntimeCoordinationRequirement implements RuntimeCoordinationRequirement
{
    public function requiresCoordinatedV1(FilterName $name): bool
    {
        return false;
    }
}
