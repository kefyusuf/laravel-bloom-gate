<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryGenerationContractStore;

final class MemoryGenerationContractStoreTest extends GenerationContractStoreContractTestCase
{
    protected function makeDriver(): BloomDriver&BloomGenerationInspector
    {
        return new MemoryBloomDriver;
    }

    protected function makeStore(
        BloomGenerationInspector $inspector,
    ): GenerationContractStore {
        return new MemoryGenerationContractStore($inspector);
    }
}
