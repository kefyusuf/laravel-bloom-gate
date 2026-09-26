<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/Application/Task18ProductionSafetyFixtures.php';

use Kefyusuf\BloomGate\Application\ProductionSafetyDoctor;
use Kefyusuf\BloomGate\Contracts\Diagnostics\RedisRuntimeDiagnostics;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\ProductionSafetyCheckStatus;
use Kefyusuf\BloomGate\Core\ProductionSafetySettings;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18DurabilityUnavailableRedisDiagnostics;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18HealthyRedisDiagnostics;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18MalformedDurabilityRedisDiagnostics;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18MalformedRuntimeRedisDiagnostics;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18NoFilterInspector;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18NoFilterRegistry;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18StaticProductionSafetyConfiguration;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18UnavailableRedisDiagnostics;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18UnsupportedRedisDiagnostics;

function task18Doctor(
    ProductionSafetySettings $settings,
    RedisRuntimeDiagnostics $diagnostics,
): ProductionSafetyDoctor {
    return new ProductionSafetyDoctor(
        configuration: new Task18StaticProductionSafetyConfiguration($settings),
        registry: new Task18NoFilterRegistry,
        filters: new Task18NoFilterInspector,
        redis: $diagnostics,
    );
}

it('classifies the complete declared redis production profile deterministically', function (): void {
    $doctor = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        new Task18HealthyRedisDiagnostics,
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
        new Task18HealthyRedisDiagnostics,
    )->inspect();

    expect($report->status('trusted_negative_profile'))
        ->toBe(ProductionSafetyCheckStatus::NotEnabled)
        ->and($report->status('redis_reachable'))
        ->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->hasFailures())->toBeFalse();
});

it('makes every unsupported redis production prerequisite visible', function (): void {
    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        new Task18UnsupportedRedisDiagnostics,
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
    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        new Task18DurabilityUnavailableRedisDiagnostics,
    )->inspect();

    expect($report->status('redis_reachable'))->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_aof'))->toBe(ProductionSafetyCheckStatus::Fail)
        ->and($report->status('redis_appendfsync'))->toBe(ProductionSafetyCheckStatus::Fail)
        ->and($report->status('redis_maxmemory_policy'))->toBe(ProductionSafetyCheckStatus::Fail);
});

it('classifies an unreachable redis runtime without manufacturing prerequisite passes', function (): void {
    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        new Task18UnavailableRedisDiagnostics,
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

it('reports an unrecognized trusted-negative profile with a matching failure message', function (): void {
    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'unsupported-profile',
            keyspacePrefix: 'lbg',
            filterNames: [],
        ),
        new Task18HealthyRedisDiagnostics,
    )->inspect();

    $message = null;

    foreach ($report->checks() as $check) {
        if ($check->code() === 'trusted_negative_profile') {
            $message = $check->message();
            break;
        }
    }

    expect($report->status('trusted_negative_profile'))
        ->toBe(ProductionSafetyCheckStatus::Fail)
        ->and($message)
        ->toBe('Redis trusted-negative profile declaration is not recognized.');
});

it('converts malformed redis runtime diagnostics into failed checks and continues filter evaluation', function (): void {
    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [
                FilterName::fromString('users.email'),
            ],
        ),
        new Task18MalformedRuntimeRedisDiagnostics,
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

    expect($report->status('filter.users.email.definition'))
        ->toBe(ProductionSafetyCheckStatus::Fail);
});

it('converts malformed redis durability diagnostics into failed durability checks and continues filter evaluation', function (): void {
    $report = task18Doctor(
        new ProductionSafetySettings(
            driver: 'redis',
            trustedNegativeProfile: 'standalone-primary-durable-v1',
            keyspacePrefix: 'lbg',
            filterNames: [
                FilterName::fromString('users.email'),
            ],
        ),
        new Task18MalformedDurabilityRedisDiagnostics,
    )->inspect();

    expect($report->status('redis_reachable'))
        ->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_version'))
        ->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_topology'))
        ->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_primary'))
        ->toBe(ProductionSafetyCheckStatus::Pass)
        ->and($report->status('redis_aof'))
        ->toBe(ProductionSafetyCheckStatus::Fail)
        ->and($report->status('redis_appendfsync'))
        ->toBe(ProductionSafetyCheckStatus::Fail)
        ->and($report->status('redis_maxmemory_policy'))
        ->toBe(ProductionSafetyCheckStatus::Fail)
        ->and($report->status('filter.users.email.definition'))
        ->toBe(ProductionSafetyCheckStatus::Fail);
});
