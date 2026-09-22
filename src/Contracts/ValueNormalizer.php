<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;

interface ValueNormalizer
{
    public function identity(): NormalizationIdentity;

    public function normalize(string|int $value): NormalizedValue;
}
