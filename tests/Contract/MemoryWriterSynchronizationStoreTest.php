<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Memory\MemoryCoordinationContractFixture;

final class MemoryWriterSynchronizationStoreTest extends WriterSynchronizationStoreContractTestCase
{
    protected function newFixture(): WriterSynchronizationStoreContractFixture
    {
        return new MemoryCoordinationContractFixture;
    }
}
