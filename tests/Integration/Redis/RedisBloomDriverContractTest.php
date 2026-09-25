<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Contract\BloomDriverContractTestCase;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[Group('redis')]
final class RedisBloomDriverContractTest extends BloomDriverContractTestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = RedisTestKeyPrefix::unique('lbgtest');
    }

    protected function tearDown(): void
    {
        $driver = $this->makeDriver();

        foreach ([
            'users.email',
            'orders.email',
            'Users.Email',
        ] as $name) {
            foreach ([1, 2] as $version) {
                try {
                    $driver->destroy(
                        FilterName::fromString($name),
                        FilterVersion::fromInt($version),
                    );
                } catch (Throwable) {
                    // Cleanup must not mask the primary test failure.
                }
            }
        }

        parent::tearDown();
    }

    protected function makeDriver(): BloomDriver
    {
        return new RedisBloomDriver(
            new RespRedisCommandExecutor(
                (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
                (int) (getenv('REDIS_PORT') ?: 6379),
            ),
            RedisKeyspace::fromPrefix($this->prefix),
        );
    }
}
