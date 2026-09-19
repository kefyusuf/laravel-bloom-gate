<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;

final class MemoryFilterControlStoreTest extends FilterControlStoreContractTestCase
{
    protected function makeStore(): FilterControlStore
    {
        return new MemoryFilterControlStore;
    }
}
