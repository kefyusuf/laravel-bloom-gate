<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Kefyusuf\BloomGate\Laravel\Redis\LaravelRedisRuntimeDiagnostics;
use Kefyusuf\BloomGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('redis')]
final class LaravelRedisRuntimeDiagnosticsIntegrationTest extends TestCase
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

    public function test_reads_runtime_identity_from_real_redis_eight(): void
    {
        $diagnostics = $this->diagnostics();
        $runtime = $diagnostics->runtime();

        self::assertStringStartsWith('8.', $runtime->version());
        self::assertSame('standalone', $runtime->mode());
        self::assertSame('master', $runtime->role());
    }

    public function test_reads_real_redis_durability_settings_without_mutating_them(): void
    {
        $diagnostics = $this->diagnostics();
        $before = $diagnostics->durability();
        $after = $diagnostics->durability();

        self::assertFalse($before->appendOnly());
        self::assertSame('everysec', $before->appendFsync());
        self::assertSame('noeviction', $before->maxmemoryPolicy());

        self::assertSame($before->appendOnly(), $after->appendOnly());
        self::assertSame($before->appendFsync(), $after->appendFsync());
        self::assertSame(
            $before->maxmemoryPolicy(),
            $after->maxmemoryPolicy(),
        );
    }

    private function diagnostics(): LaravelRedisRuntimeDiagnostics
    {
        $app = $this->app;

        self::assertNotNull($app);

        $manager = $app->make('redis');

        self::assertInstanceOf(RedisManager::class, $manager);

        $connection = $manager->connection('default');

        self::assertInstanceOf(Connection::class, $connection);

        return new LaravelRedisRuntimeDiagnostics(
            static fn (): Connection => $connection,
        );
    }
}
