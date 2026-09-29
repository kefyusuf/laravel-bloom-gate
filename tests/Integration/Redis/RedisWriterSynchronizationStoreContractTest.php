<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Tests\Contract\Support\WriterSynchronizationStoreContractFixture;
use Kefyusuf\BloomGate\Tests\Contract\WriterSynchronizationStoreContractTestCase;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisWriterSynchronizationContractFixture;
use PHPUnit\Framework\Attributes\Group;

#[Group('redis')]
final class RedisWriterSynchronizationStoreContractTest extends WriterSynchronizationStoreContractTestCase
{
    protected function newFixture(): WriterSynchronizationStoreContractFixture
    {
        return new RedisWriterSynchronizationContractFixture;
    }
}
