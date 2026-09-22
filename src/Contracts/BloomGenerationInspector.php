<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;

interface BloomGenerationInspector
{
    public function layout(
        FilterName $name,
        FilterVersion $version,
    ): ?BloomLayout;
}
