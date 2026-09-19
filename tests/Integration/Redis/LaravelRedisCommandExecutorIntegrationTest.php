<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Kefyusuf\BloomGate\Laravel\Redis\LaravelRedisCommandExecutor;
use Kefyusuf\BloomGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('redis')]
final class LaravelRedisCommandExecutorIntegrationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.redis', [
            'client' => 'phpredis',
            'default' => [
                'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
                'password' => null,
                'port' => (int) (getenv('REDIS_PORT') ?: 6379),
                'database' => 0,
            ],
        ]);
    }

    public function test_executes_eval_through_a_real_testbench_redis_connection(): void
    {
        $app = $this->app;

        self::assertNotNull($app);

        $manager = $app->make('redis');

        self::assertInstanceOf(RedisManager::class, $manager);

        $connection = $manager->connection('default');

        self::assertInstanceOf(Connection::class, $connection);

        $executor = new LaravelRedisCommandExecutor($connection);

        self::assertSame(42, $executor->evaluate(
            'return tonumber(ARGV[1]) + tonumber(ARGV[2])',
            ['lbg:{adapter-evidence}:v:1:meta'],
            ['20', '22'],
        ));
    }
}
