<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use Kefyusuf\BloomGate\Contracts\QuerySafetyDescriptorCache;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;

final readonly class NullQuerySafetyDescriptorCache implements QuerySafetyDescriptorCache
{
    public function get(FilterName $name, GenerationSemanticContract $expectedContract): ?QuerySafetyDescriptor
    {
        return null;
    }

    public function put(QuerySafetyDescriptor $descriptor): void {}

    public function forget(FilterName $name): void {}
}
