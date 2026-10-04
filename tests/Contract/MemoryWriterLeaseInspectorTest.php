<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Contract;

use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryWriterSynchronizationStore;

final class MemoryWriterLeaseInspectorTest extends WriterLeaseInspectorContractTestCase
{
    protected function stores(): array
    {
        $domain = new MemoryCoordinationDomain;

        return [new MemoryCoordinatedLifecycleStore($domain), new MemoryWriterSynchronizationStore($domain)];
    }
}
