<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;

final class MemoryBloomDriverContractTest extends BloomDriverContractTestCase
{
    protected function makeDriver(): BloomDriver
    {
        return new MemoryBloomDriver;
    }
}
