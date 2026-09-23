<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;

interface BulkBloomDriver extends BloomDriver
{
    /**
     * @param  list<BitPositions>  $items
     */
    public function addMany(
        FilterName $name,
        FilterVersion $version,
        array $items,
    ): void;
}
