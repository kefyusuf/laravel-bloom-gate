<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;

interface AuthorizedProbe
{
    public function probe(
        QuerySafetyDescriptor $descriptor,
        BitPositions $positions,
    ): AuthorizedProbeResult;
}
