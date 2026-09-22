<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts;

use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;

interface GenerationContractStore
{
    public function read(
        FilterName $name,
        FilterVersion $version,
    ): ?ManagedGenerationDescriptor;

    public function bind(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $expectedLayout,
        GenerationSemanticContract $semanticContract,
    ): void;
}
