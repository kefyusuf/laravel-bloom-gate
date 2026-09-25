<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\Diagnostics\Exception\RedisDiagnosticsUnavailable;
use Kefyusuf\BloomGate\Contracts\Diagnostics\RedisRuntimeDiagnostics;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\ProductionFilterInspector;
use Kefyusuf\BloomGate\Contracts\ProductionSafetyConfiguration;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\ProductionFilterRuntimeStatus;
use Kefyusuf\BloomGate\Core\ProductionSafetySettings;
use Kefyusuf\BloomGate\Core\RedisDurabilitySettings;
use Kefyusuf\BloomGate\Core\RedisRuntimeInfo;
use LogicException;

final class Task18NoFilterRegistry implements FilterRegistry
{
    public function globalQueryOptimizationEnabled(): bool
    {
        return true;
    }

    public function get(FilterName $name): RegisteredFilter
    {
        throw new LogicException(
            'Task 18 no-filter fixture must not resolve filters.',
        );
    }
}

final class Task18NoFilterInspector implements ProductionFilterInspector
{
    public function inspect(FilterName $name): ProductionFilterRuntimeStatus
    {
        throw new LogicException(
            'Task 18 no-filter fixture must not inspect filter runtime state.',
        );
    }
}

final readonly class Task18StaticProductionSafetyConfiguration implements ProductionSafetyConfiguration
{
    public function __construct(
        private ProductionSafetySettings $settings,
    ) {}

    public function resolve(): ProductionSafetySettings
    {
        return $this->settings;
    }
}

final class Task18HealthyRedisDiagnostics implements RedisRuntimeDiagnostics
{
    public function runtime(): RedisRuntimeInfo
    {
        return new RedisRuntimeInfo(
            version: '8.2.1',
            mode: 'standalone',
            role: 'master',
        );
    }

    public function durability(): RedisDurabilitySettings
    {
        return new RedisDurabilitySettings(
            appendOnly: true,
            appendFsync: 'always',
            maxmemoryPolicy: 'noeviction',
        );
    }
}

final class Task18UnsupportedRedisDiagnostics implements RedisRuntimeDiagnostics
{
    public function runtime(): RedisRuntimeInfo
    {
        return new RedisRuntimeInfo(
            version: '7.4.0',
            mode: 'cluster',
            role: 'slave',
        );
    }

    public function durability(): RedisDurabilitySettings
    {
        return new RedisDurabilitySettings(
            appendOnly: false,
            appendFsync: 'everysec',
            maxmemoryPolicy: 'allkeys-lru',
        );
    }
}

final class Task18DurabilityUnavailableRedisDiagnostics implements RedisRuntimeDiagnostics
{
    public function runtime(): RedisRuntimeInfo
    {
        return new RedisRuntimeInfo(
            version: '8.2.1',
            mode: 'standalone',
            role: 'master',
        );
    }

    public function durability(): RedisDurabilitySettings
    {
        throw new RedisDiagnosticsUnavailable(
            'CONFIG GET is not permitted by the Redis ACL.',
        );
    }
}

final class Task18UnavailableRedisDiagnostics implements RedisRuntimeDiagnostics
{
    public function runtime(): RedisRuntimeInfo
    {
        throw new RedisDiagnosticsUnavailable('Redis connection refused.');
    }

    public function durability(): RedisDurabilitySettings
    {
        throw new LogicException(
            'Durability must not run after runtime reachability failed.',
        );
    }
}
