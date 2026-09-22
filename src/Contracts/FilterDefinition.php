<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\ConsistencyContract;

interface FilterDefinition
{
    public function normalizer(): ValueNormalizer;

    public function authoritativeSet(): AuthoritativeSet;

    public function consistency(): ConsistencyContract;
}
