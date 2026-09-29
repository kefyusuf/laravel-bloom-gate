<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Tests\Contract\CoordinatedLifecycleStoreContractTestCase;
use Kefyusuf\BloomGate\Tests\Contract\Support\CoordinatedLifecycleStoreContractFixture;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisCoordinatedLifecycleContractFixture;
use PHPUnit\Framework\Attributes\Group;

#[Group('redis')]
final class RedisCoordinatedLifecycleStoreContractTest extends CoordinatedLifecycleStoreContractTestCase
{
    protected function newFixture(): CoordinatedLifecycleStoreContractFixture
    {
        return new RedisCoordinatedLifecycleContractFixture;
    }
}
