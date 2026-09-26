<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use Kefyusuf\BloomGate\Contracts\Diagnostics\Exception\RedisDiagnosticsInvalid;
use Kefyusuf\BloomGate\Contracts\Diagnostics\Exception\RedisDiagnosticsUnavailable;
use Kefyusuf\BloomGate\Laravel\Redis\LaravelRedisRuntimeDiagnostics;
use Kefyusuf\BloomGate\Tests\Support\Redis\FakeRedisClientException;
use Kefyusuf\BloomGate\Tests\Support\Redis\RecordingIlluminateRedisDiagnosticsConnection;
use LogicException;

it('reads only the redis runtime fields required by the m5 support profile', function (): void {
    $connection = new RecordingIlluminateRedisDiagnosticsConnection([
        'info:server' => [
            'redis_version' => '8.2.1',
            'redis_mode' => 'standalone',
        ],
        'info:replication' => [
            'role' => 'master',
        ],
        'config:GET:appendonly' => [
            'appendonly' => 'yes',
        ],
        'config:GET:appendfsync' => [
            'appendfsync' => 'always',
        ],
        'config:GET:maxmemory-policy' => [
            'maxmemory-policy' => 'noeviction',
        ],
    ]);

    $diagnostics = new LaravelRedisRuntimeDiagnostics(
        static fn (): Connection => $connection,
    );

    $runtime = $diagnostics->runtime();
    $durability = $diagnostics->durability();

    expect($runtime->version())->toBe('8.2.1')
        ->and($runtime->mode())->toBe('standalone')
        ->and($runtime->role())->toBe('master')
        ->and($durability->appendOnly())->toBeTrue()
        ->and($durability->appendFsync())->toBe('always')
        ->and($durability->maxmemoryPolicy())->toBe('noeviction')
        ->and($connection->calls())->toBe([
            ['method' => 'info', 'parameters' => ['server']],
            ['method' => 'info', 'parameters' => ['replication']],
            ['method' => 'config', 'parameters' => ['GET', 'appendonly']],
            ['method' => 'config', 'parameters' => ['GET', 'appendfsync']],
            ['method' => 'config', 'parameters' => ['GET', 'maxmemory-policy']],
        ]);
});

it('parses raw info text without broad redis client assumptions', function (): void {
    $connection = new RecordingIlluminateRedisDiagnosticsConnection([
        'info:server' => "# Server\r\nredis_version:8.0.3\r\nredis_mode:standalone\r\n",
        'info:replication' => "# Replication\r\nrole:master\r\n",
        'config:GET:appendonly' => ['appendonly', 'yes'],
        'config:GET:appendfsync' => ['appendfsync', 'always'],
        'config:GET:maxmemory-policy' => ['maxmemory-policy', 'noeviction'],
    ]);

    $diagnostics = new LaravelRedisRuntimeDiagnostics(
        static fn (): Connection => $connection,
    );

    expect($diagnostics->runtime()->version())->toBe('8.0.3')
        ->and($diagnostics->runtime()->mode())->toBe('standalone')
        ->and($diagnostics->runtime()->role())->toBe('master')
        ->and($diagnostics->durability()->appendOnly())->toBeTrue();
});

it('maps redis acl or transport failures to a dedicated diagnostics failure', function (): void {
    if (! class_exists('RedisException', false)) {
        class_alias(FakeRedisClientException::class, 'RedisException');
    }

    $connection = new RecordingIlluminateRedisDiagnosticsConnection(
        responses: [],
        failure: new RedisException('NOPERM this user has no permissions'),
    );
    $diagnostics = new LaravelRedisRuntimeDiagnostics(
        static fn (): Connection => $connection,
    );

    expect(fn () => $diagnostics->runtime())
        ->toThrow(RedisDiagnosticsUnavailable::class);
});

it('does not mask programming failures in diagnostics', function (): void {
    $failure = new LogicException('Programming failure.');
    $connection = new RecordingIlluminateRedisDiagnosticsConnection(
        responses: [],
        failure: $failure,
    );
    $diagnostics = new LaravelRedisRuntimeDiagnostics(
        static fn (): Connection => $connection,
    );

    try {
        $diagnostics->runtime();

        throw new LogicException('Expected original programming failure.');
    } catch (LogicException $actual) {
        expect($actual)->toBe($failure);
    }
});


it('maps malformed runtime replies to a dedicated invalid diagnostics failure', function (): void {
    $connection = new RecordingIlluminateRedisDiagnosticsConnection([
        'info:server' => [
            'redis_version' => '8.2.1',
            'redis_mode' => 'standalone',
        ],
        'info:replication' => [],
    ]);
    $diagnostics = new LaravelRedisRuntimeDiagnostics(
        static fn (): Connection => $connection,
    );

    expect(fn () => $diagnostics->runtime())
        ->toThrow(RedisDiagnosticsInvalid::class);
});

it('maps malformed durability replies to a dedicated invalid diagnostics failure', function (): void {
    $connection = new RecordingIlluminateRedisDiagnosticsConnection([
        'config:GET:appendonly' => [
            'appendonly' => 'sometimes',
        ],
    ]);
    $diagnostics = new LaravelRedisRuntimeDiagnostics(
        static fn (): Connection => $connection,
    );

    expect(fn () => $diagnostics->durability())
        ->toThrow(RedisDiagnosticsInvalid::class);
});
