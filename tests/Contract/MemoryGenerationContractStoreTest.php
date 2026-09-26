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
    private MemoryBloomDriver $driver;

    private MemoryGenerationContractStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new MemoryBloomDriver;
        $this->store = new MemoryGenerationContractStore($this->driver);
    }

    protected function driver(): BloomDriver
    {
        return $this->driver;
    }

    protected function inspector(): BloomGenerationInspector
    {
        return $this->driver;
    }

    protected function store(): GenerationContractStore
    {
        return $this->store;
    }
}
