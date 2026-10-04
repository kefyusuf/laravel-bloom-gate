<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLease;

/** Optional diagnostics; lease enumeration is never a drain authorization. */
interface WriterLeaseInspector
{
    /**
     * @return list<WriterLease> Active A/P records, ordered by token.
     *
     * @phpstan-impure
     */
    public function readActiveLeases(FilterName $name): array;
}
