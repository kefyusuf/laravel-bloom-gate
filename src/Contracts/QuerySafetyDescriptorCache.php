<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;

interface QuerySafetyDescriptorCache
{
    public function get(FilterName $name, GenerationSemanticContract $expectedContract): ?QuerySafetyDescriptor;

    public function put(QuerySafetyDescriptor $descriptor): void;

    public function forget(FilterName $name): void;
}
