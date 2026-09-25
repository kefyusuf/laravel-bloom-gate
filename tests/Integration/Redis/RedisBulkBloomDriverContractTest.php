<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Contract\BulkBloomDriverContractTestCase;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[Group('redis')]
final class RedisBulkBloomDriverContractTest extends BulkBloomDriverContractTestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'lgbbulk'.bin2hex((string) random_bytes(8));
    }

    protected function tearDown(): void
    {
        try {
            $this->makeBulkDriver()->destroy(
                FilterName::fromString('users.email'),
                FilterVersion::fromInt(1),
            );
        } catch (Throwable) {
            // Cleanup must not mask the primary failure.
        }

        parent::tearDown();
    }

    protected function makeBulkDriver(): BulkBloomDriver
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
