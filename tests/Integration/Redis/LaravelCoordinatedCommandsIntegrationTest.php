<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Integration\Redis;

use Illuminate\Support\Facades\Artisan;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Redis\RedisCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisWriterSynchronizationStore;
use Kefyusuf\BloomGate\Laravel\Facades\BloomGate;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task17FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Redis\RedisTestKeyPrefix;
use Kefyusuf\BloomGate\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('redis')]
final class LaravelCoordinatedCommandsIntegrationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.redis', ['client' => 'phpredis', 'default' => [
            'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            'password' => null, 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => 0,
        ]]);
        $app['config']->set('bloom-gate.default', 'redis');
        $app['config']->set('bloom-gate.keyspace.prefix', RedisTestKeyPrefix::unique('lbgwu11'));
        $app['config']->set('bloom-gate.filters', ['users.email' => [
            'enabled' => true, 'definition' => Task17FilterDefinition::class,
            'capacity' => 100, 'false_positive_rate' => 0.01, 'coordination' => 'coordinated-v1',
        ]]);
        $app->instance(Task17FilterDefinition::class, new Task17FilterDefinition(['one@example.test'], ConsistencyContract::PreAddV1));
    }

    public function test_laravel_ports_commands_and_writer_share_live_redis_coordination(): void
    {
        self::assertInstanceOf(RedisCoordinatedLifecycleStore::class, app(CoordinatedLifecycleStore::class));
        self::assertInstanceOf(RedisWriterSynchronizationStore::class, app(WriterSynchronizationStore::class));
        self::assertSame(0, Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']));
        self::assertSame(0, Artisan::call('bloom:rebuild', ['filter' => 'users.email']));
        self::assertStringContainsString('Completed', Artisan::output());
        $token = str_repeat('a', 32);
        $prepared = BloomGate::prepare('users.email', $token, ['two@example.test']);
        self::assertSame(WriterLeaseState::Prepared, $prepared->lease()->state());
        self::assertSame(0, Artisan::call('bloom:status', ['filter' => 'users.email', '--leases' => true]));
        self::assertStringContainsString($token, Artisan::output());
        self::assertSame(0, Artisan::call('bloom:lease:resolve', ['filter' => 'users.email', 'token' => $token, '--outcome' => 'committed']));
        self::assertSame(WriterLeaseState::Released, app(WriterSynchronizationStore::class)->readLease(
            FilterName::fromString('users.email'), WriterLeaseToken::fromString($token))?->state());
        self::assertSame(0, Artisan::call('bloom:rebuild', ['filter' => 'users.email']));
        self::assertSame(0, Artisan::call('bloom:rebuild:abort', ['filter' => 'users.email']));
    }
}
