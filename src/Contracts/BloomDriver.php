<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;

interface BloomDriver
{
    public function provision(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
    ): void;

    public function add(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): void;

    public function mightContain(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): bool;

    public function destroy(
        FilterName $name,
        FilterVersion $version,
    ): void;
}
