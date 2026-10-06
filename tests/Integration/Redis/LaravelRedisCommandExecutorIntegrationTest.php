<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Laravel\Redis\LaravelRedisCommandExecutor;
use Kefyusuf\BloomGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_executes_structured_eval_through_a_real_testbench_redis_connection(): void
    {
        $app = $this->app;

        self::assertNotNull($app);

        $manager = $app->make('redis');

        self::assertInstanceOf(RedisManager::class, $manager);

        $connection = $manager->connection('default');

        self::assertInstanceOf(Connection::class, $connection);

        $executor = new LaravelRedisCommandExecutor($connection);

        self::assertSame(
            ['control-v1', '7', '3'],
            $executor->evaluateStructured(
                "return {'control-v1', '7', '3'}",
                ['lbg:{adapter-evidence}:state'],
                [],
            ),
        );
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

    /** @return iterable<string, array{string}> */
    public static function clients(): iterable
    {
        yield 'phpredis' => ['phpredis'];
        yield 'predis' => ['predis'];
    }

    #[DataProvider('clients')]
    public function test_script_flush_recovers_once_and_warm_calls_use_evalsha(string $client): void
    {
        $connection = $this->clientConnection($client);
        $executor = new LaravelRedisCommandExecutor($connection);
        $key = 'lbg:{evalsha-'.bin2hex(random_bytes(12)).'}:counter';
        $script = "return redis.call('INCR', KEYS[1])";

        try {
            $connection->command('script', ['flush']);
            self::assertSame(1, $executor->evaluate($script, [$key], []));
            self::assertSame('1', $this->readCounter($connection, $key));

            $before = $this->commandCounts($connection);
            self::assertSame(2, $executor->evaluate($script, [$key], []));
            $after = $this->commandCounts($connection);
            self::assertSame($before['evalsha'] + 1, $after['evalsha']);
            self::assertSame($before['eval'], $after['eval']);
            self::assertSame($before['load'], $after['load']);

            $connection->command('script', ['flush']);
            self::assertSame(3, $executor->evaluate($script, [$key], []));
            self::assertSame('3', $this->readCounter($connection, $key));

            $connection->command('script', ['flush']);
            self::assertSame(['3'], $executor->evaluateStructured(
                "return {redis.call('GET', KEYS[1])}", [$key], []));
        } finally {
            $connection->command('del', [$key]);
        }
    }

    #[DataProvider('clients')]
    public function test_lua_error_after_a_write_does_not_replay_the_write(string $client): void
    {
        $connection = $this->clientConnection($client);
        $executor = new LaravelRedisCommandExecutor($connection);
        $key = 'lbg:{evalsha-error-'.bin2hex(random_bytes(12)).'}:counter';
        $script = "redis.call('INCR', KEYS[1]); return redis.error_reply('NOSCRIPT user-defined Lua error')";

        try {
            $connection->command('script', ['load', $script]);
            try {
                $executor->evaluate($script, [$key], []);
                self::fail('Expected the Lua error to propagate as an operational Redis failure.');
            } catch (RedisCommandFailed) {
                self::assertSame('1', $this->readCounter($connection, $key));
            }
        } finally {
            $connection->command('del', [$key]);
        }
    }

    private function clientConnection(string $client): Connection
    {
        $app = $this->app;
        self::assertNotNull($app);
        $manager = new RedisManager($app, $client, [
            'default' => [
                'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
                'port' => (int) (getenv('REDIS_PORT') ?: 6379),
                'database' => 0,
            ],
        ]);

        return $manager->connection('default');
    }

    /** @return array{eval: int, evalsha: int, load: int} */
    private function commandCounts(Connection $connection): array
    {
        $info = $connection->command('info', ['commandstats']);
        self::assertIsArray($info);
        if (isset($info['Commandstats']) && is_array($info['Commandstats'])) {
            $info = $info['Commandstats'];
        }
        $counts = [];
        foreach (['eval' => 'cmdstat_eval', 'evalsha' => 'cmdstat_evalsha', 'load' => 'cmdstat_script|load'] as $command => $field) {
            $encoded = $info[$field] ?? 'calls=0';
            self::assertIsString($encoded);
            if (preg_match('/(?:^|,)calls=([0-9]+)/', $encoded, $matches) !== 1) {
                self::fail('Command stats must contain a canonical call count.');
            }
            $counts[$command] = (int) $matches[1];
        }

        return $counts;
    }

    private function readCounter(Connection $connection, string $key): string
    {
        $value = $connection->command('get', [$key]);
        self::assertIsString($value);

        return $value;
    }
}
