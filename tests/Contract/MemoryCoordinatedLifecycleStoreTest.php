<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Tests\Contract\Support\CoordinatedLifecycleStoreContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryCoordinationContractFixture;

final class MemoryCoordinatedLifecycleStoreTest extends CoordinatedLifecycleStoreContractTestCase
{
    protected function newFixture(): CoordinatedLifecycleStoreContractFixture
    {
        return new MemoryCoordinationContractFixture;
    }
}
