<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;

final class MemoryBulkBloomDriverContractTest extends BulkBloomDriverContractTestCase
{
    protected function makeBulkDriver(): BulkBloomDriver
    {
        return new MemoryBloomDriver;
    }
}
