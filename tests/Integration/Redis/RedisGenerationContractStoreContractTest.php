<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Contract\GenerationContractStoreContractTestCase;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[Group('redis')]
final class RedisGenerationContractStoreContractTest extends GenerationContractStoreContractTestCase
{
    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    private RedisBloomDriver $driver;

    private RedisGenerationContractStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $prefix = RedisTestKeyPrefix::unique('lbgsemantic');
        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix($prefix);
        $this->driver = new RedisBloomDriver($this->executor, $this->keyspace);
        $this->store = new RedisGenerationContractStore($this->executor, $this->keyspace);
    }

    protected function tearDown(): void
    {
        foreach ([1, 2] as $version) {
            try {
                $this->driver->destroy(
                    FilterName::fromString('users.email'),
                    FilterVersion::fromInt($version),
                );
            } catch (Throwable) {
                // Cleanup must not mask the primary failure.
            }
        }

        parent::tearDown();
    }

    protected function driver(): BloomDriver
    {
        return $this->driver;
    }

    protected function inspector(): BloomGenerationInspector
    {
        return $this->store;
    }

    protected function store(): GenerationContractStore
    {
        return $this->store;
    }
}
