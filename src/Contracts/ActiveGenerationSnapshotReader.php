<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\FilterName;

interface ActiveGenerationSnapshotReader
{
    public function readActive(FilterName $name): ?ActiveGenerationSnapshot;
}
