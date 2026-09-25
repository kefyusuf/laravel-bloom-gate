<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Application\ManagedFilterStatusReader;
use Kefyusuf\BloomGate\Application\ProductionSafetyDoctor;
use Kefyusuf\BloomGate\Contracts\Diagnostics\Exception\RedisDiagnosticsUnavailable;
use Kefyusuf\BloomGate\Contracts\Diagnostics\RedisRuntimeDiagnostics;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\ProductionSafetyConfiguration;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\ProductionSafetyCheckStatus;
use Kefyusuf\BloomGate\Core\ProductionSafetySettings;
use Kefyusuf\BloomGate\Core\RedisDurabilitySettings;
use Kefyusuf\BloomGate\Core\RedisRuntimeInfo;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryGenerationContractStore;
use LogicException;

function task18Doctor(
    ProductionSafetySettings $settings,
    RedisRuntimeDiagnostics $diagnostics,
): ProductionSafetyDoctor {
    $registry = new class implements FilterRegistry
    {
        public function globalQueryOptimizationEnabled(): bool
        {
            return true;
        }

        public function get(FilterName $name): RegisteredFilter
        {
            throw new LogicException('Task 18 no-filter fixture must not resolve filters.');
        }
    };

    $driver = new MemoryBloomDriver;
    $control = new MemoryFilterControlStore;
    $contracts = new MemoryGenerationContractStore($driver);

    $statuses = new ManagedFilterStatusReader(
        registry: $registry,
        control: $control,
        inspector: $driver,
        contracts: $contracts,
        fingerprints: new SemanticFingerprintCalculator,
    );

    $configuration = new class($settings) implements ProductionSafetyConfiguration
    {
        public function __construct(
            private readonly ProductionSafetySettings $settings,
        ) {}

        public function resolve(): ProductionSafetySettings
        {
            return $this->settings;
        }
    };

    return new ProductionSafetyDoctor(
        configuration: $configuration,
        registry: $registry,
        statuses: $statuses,
        redis: $diagnostics,
    );
}

function task18HealthyRedisDiagnostics(): RedisRuntimeDiagnostics
{
    return new class implements RedisRuntimeDiagnostics
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
    };
}

it('classifies the complete declared redis production profile deterministically', function (): void {
    $doctor = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        task18HealthyRedisDiagnostics(),
    );

    $report = $doctor->inspect();

    expect($report->status('package_config'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('keyspace_prefix'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('trusted_negative_profile'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_reachable'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_version'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_topology'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_primary'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_aof'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_appendfsync'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_maxmemory_policy'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->hasFailures())->toBeFalse();
});

it('reports a missing trusted-negative profile as not enabled and never pass', function (): void {
    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: null,
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        task18HealthyRedisDiagnostics(),
    )->inspect();

    expect($report->status('trusted_negative_profile'))
        ->toBe(ProductionSafetyCheckStatus::NotEnabled)
        ->and($report->status('redis_reachable'))
        ->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->hasFailures())->toBeFalse();
});

it('makes every unsupported redis production prerequisite visible', function (): void {
    $diagnostics = new class implements RedisRuntimeDiagnostics
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
    };

    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        $diagnostics,
    )->inspect();

    foreach ([
        'redis_version',
        'redis_topology',
        'redis_primary',
        'redis_aof',
        'redis_appendfsync',
        'redis_maxmemory_policy',
    ] as $code) {
        expect($report->status($code))->toBe(ProductionSafetyCheckStatus::Fail);
    }

    expect($report->hasFailures())->toBeTrue();
});

it('never reports inaccessible durability prerequisites as pass', function (): void {
    $diagnostics = new class implements RedisRuntimeDiagnostics
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
    };

    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        $diagnostics,
    )->inspect();

    expect($report->status('redis_reachable'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_aof'))->toBe(ProductionSafetyCheckStatus::Fail)
        ->and($report->status('redis_appendfsync'))->toBe(ProductionSafetyCheckStatus::Fail)
        ->and($report->status('redis_maxmemory_policy'))->toBe(ProductionSafetyCheckStatus::Fail);
});

it('classifies an unreachable redis runtime without manufacturing prerequisite passes', function (): void {
    $diagnostics = new class implements RedisRuntimeDiagnostics
    {
        public function runtime(): RedisRuntimeInfo
        {
            throw new RedisDiagnosticsUnavailable('Redis connection refused.');
        }

        public function durability(): RedisDurabilitySettings
        {
            throw new LogicException('Durability must not run after runtime reachability failed.');
        }
    };

    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        $diagnostics,
    )->inspect();

    foreach ([
        'redis_reachable',
        'redis_version',
        'redis_topology',
        'redis_primary',
        'redis_aof',
        'redis_appendfsync',
        'redis_maxmemory_policy',
    ] as $code) {
        expect($report->status($code))->toBe(ProductionSafetyCheckStatus::Fail);
    }
});
