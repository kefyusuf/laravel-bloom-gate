<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Tests\Contract\FilterControlStoreContractTestCase;
use Kefyusuf\BloomGate\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[Group('redis')]
final class RedisFilterControlStoreContractTest extends FilterControlStoreContractTestCase
{
    private string $prefix;

    private RespRedisCommandExecutor $executor;

    private RedisKeyspace $keyspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'lbgcontrol'.bin2hex((string) random_bytes(8));
        $this->executor = new RespRedisCommandExecutor(
            (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('REDIS_PORT') ?: 6379),
        );
        $this->keyspace = RedisKeyspace::fromPrefix($this->prefix);
    }

    protected function tearDown(): void
    {
        foreach ([
            'users.email',
            'Users.Email',
            'products.sku',
        ] as $name) {
            try {
                $this->executor->evaluate(
                    "return redis.call('DEL', KEYS[1])",
                    [$this->keyspace->stateKey(FilterName::fromString($name))],
                    [],
                );
            } catch (Throwable) {
                // Cleanup must not mask the primary test failure.
            }
        }

        parent::tearDown();
    }

    protected function makeStore(): FilterControlStore
    {
        return new RedisFilterControlStore(
            executor: $this->executor,
            keyspace: $this->keyspace,
            codec: new RedisControlStateCodec,
        );
    }
}
