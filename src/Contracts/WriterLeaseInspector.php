<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLease;

interface WriterLeaseInspector
{
    /**
     * @return list<WriterLease>
     */
    public function activeLeases(FilterName $name): array;
}
