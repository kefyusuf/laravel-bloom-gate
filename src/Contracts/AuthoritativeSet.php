<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;

interface AuthoritativeSet
{
    public function identity(): AuthoritativeSetIdentity;

    public function exists(NormalizedValue $value): bool;

    /**
     * @return iterable<string|int>
     */
    public function values(): iterable;
}
