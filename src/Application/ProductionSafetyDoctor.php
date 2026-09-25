<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Contracts\Diagnostics\Exception\RedisDiagnosticsUnavailable;
use Kefyusuf\BloomGate\Contracts\Diagnostics\RedisRuntimeDiagnostics;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\ProductionFilterInspector;
use Kefyusuf\BloomGate\Contracts\ProductionSafetyConfiguration;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\ProductionSafetyCheckStatus;
use Throwable;

final readonly class ProductionSafetyDoctor
{
    private const string REDIS_PROFILE = 'standalone-primary-durable-v1';

    public function __construct(
        private ProductionSafetyConfiguration $configuration,
        private FilterRegistry $registry,
        private ProductionFilterInspector $filters,
        private RedisRuntimeDiagnostics $redis,
    ) {
        // Dependencies are framework-neutral and side-effect free until inspect().
    }

    public function inspect(): ProductionSafetyReport
    {
        try {
            $settings = $this->configuration->resolve();
        } catch (Throwable) {
            return new ProductionSafetyReport([
                $this->check(
                    'package_config',
                    ProductionSafetyCheckStatus::Fail,
                    'Bloom Gate package configuration is invalid.',
                ),
            ]);
        }

        $checks = [
            $this->check(
                'package_config',
                ProductionSafetyCheckStatus::Pass,
                'Bloom Gate package configuration is valid.',
            ),
            $this->check(
                'keyspace_prefix',
                ProductionSafetyCheckStatus::Pass,
                'Bloom Gate Redis keyspace prefix is valid.',
            ),
        ];

        if ($settings->driver() !== 'redis') {
            $checks[] = $this->check(
                'trusted_negative_profile',
                ProductionSafetyCheckStatus::NotEnabled,
                'Redis trusted-negative authorization is not enabled for the selected driver.',
            );
        } elseif ($settings->trustedNegativeProfile() === null) {
            $checks[] = $this->check(
                'trusted_negative_profile',
                ProductionSafetyCheckStatus::NotEnabled,
                'Redis trusted-negative profile is not declared; negatives cannot authorize query skipping.',
            );
        } else {
            $checks[] = $this->check(
                'trusted_negative_profile',
                $settings->trustedNegativeProfile() === self::REDIS_PROFILE
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                'Redis trusted-negative profile declaration is recognized.',
            );
        }

        if ($settings->driver() === 'redis') {
            $checks = [
                ...$checks,
                ...$this->redisChecks(),
            ];
        }

        foreach ($settings->filterNames() as $name) {
            $prefix = 'filter.'.$name->value();

            try {
                $this->registry->get($name);
                $checks[] = $this->check(
                    $prefix.'.definition',
                    ProductionSafetyCheckStatus::Pass,
                    'Registered filter configuration and definition resolve successfully.',
                );
            } catch (Throwable) {
                $checks[] = $this->check(
                    $prefix.'.definition',
                    ProductionSafetyCheckStatus::Fail,
                    'Registered filter configuration or definition could not be resolved.',
                );

                continue;
            }

            try {
                $runtime = $this->filters->inspect($name);
                $checks[] = $this->check(
                    $prefix.'.control_state',
                    ProductionSafetyCheckStatus::Pass,
                    'Filter control state is readable and valid.',
                );
            } catch (Throwable) {
                $checks[] = $this->check(
                    $prefix.'.control_state',
                    ProductionSafetyCheckStatus::Fail,
                    'Filter control state is unavailable or invalid.',
                );

                continue;
            }

            if ($runtime->hasActiveGeneration() === false) {
                $checks[] = $this->check(
                    $prefix.'.active_layout',
                    ProductionSafetyCheckStatus::Warn,
                    'No active generation is present.',
                );
                $checks[] = $this->check(
                    $prefix.'.active_semantics',
                    ProductionSafetyCheckStatus::Warn,
                    'No active generation semantic binding is present.',
                );

                continue;
            }

            $activeHealthy = $runtime->activeLifecycle() === LifecycleState::Active
                && $runtime->activeHealth() === HealthState::Healthy;

            $checks[] = $this->check(
                $prefix.'.active_state',
                $activeHealthy
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                $activeHealthy
                    ? 'Active generation lifecycle and health are trusted-negative eligible.'
                    : 'Active generation lifecycle or health is not trusted-negative eligible.',
            );

            $checks[] = $this->check(
                $prefix.'.active_layout',
                $runtime->activeLayoutAvailable()
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                $runtime->activeLayoutAvailable()
                    ? 'Active generation storage layout is available.'
                    : 'Active generation storage layout is unavailable.',
            );

            $semanticSafe = $runtime->activeSemanticBound()
                && $runtime->activeSemanticMatches() === true;

            $checks[] = $this->check(
                $prefix.'.active_semantics',
                $semanticSafe
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                $semanticSafe
                    ? 'Active generation semantic bindings match the runtime definition.'
                    : 'Active generation semantic bindings are missing or do not match the runtime definition.',
            );
        }

        return new ProductionSafetyReport($checks);
    }

    /**
     * @return list<ProductionSafetyCheck>
     */
    private function redisChecks(): array
    {
        try {
            $runtime = $this->redis->runtime();
        } catch (RedisDiagnosticsUnavailable) {
            return [
                $this->check('redis_reachable', ProductionSafetyCheckStatus::Fail, 'Redis runtime diagnostics are unavailable.'),
                $this->check('redis_version', ProductionSafetyCheckStatus::Fail, 'Redis version could not be verified.'),
                $this->check('redis_topology', ProductionSafetyCheckStatus::Fail, 'Redis topology could not be verified.'),
                $this->check('redis_primary', ProductionSafetyCheckStatus::Fail, 'Redis primary role could not be verified.'),
                $this->check('redis_aof', ProductionSafetyCheckStatus::Fail, 'Redis AOF setting could not be verified.'),
                $this->check('redis_appendfsync', ProductionSafetyCheckStatus::Fail, 'Redis appendfsync setting could not be verified.'),
                $this->check('redis_maxmemory_policy', ProductionSafetyCheckStatus::Fail, 'Redis maxmemory policy could not be verified.'),
            ];
        }

        $checks = [
            $this->check(
                'redis_reachable',
                ProductionSafetyCheckStatus::Pass,
                'Redis runtime diagnostics are reachable.',
            ),
            $this->check(
                'redis_version',
                str_starts_with($runtime->version(), '8.')
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                str_starts_with($runtime->version(), '8.')
                    ? 'Redis major version matches the supported M5 Redis 8 profile.'
                    : 'Redis major version is outside the supported M5 Redis 8 profile.',
            ),
            $this->check(
                'redis_topology',
                $runtime->mode() === 'standalone'
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                $runtime->mode() === 'standalone'
                    ? 'Redis reports standalone mode.'
                    : 'Redis reports an unsupported topology.',
            ),
            $this->check(
                'redis_primary',
                $runtime->role() === 'master'
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                $runtime->role() === 'master'
                    ? 'Redis connection targets the authoritative primary.'
                    : 'Redis connection does not report the authoritative primary role.',
            ),
        ];

        try {
            $durability = $this->redis->durability();
        } catch (RedisDiagnosticsUnavailable) {
            return [
                ...$checks,
                $this->check('redis_aof', ProductionSafetyCheckStatus::Fail, 'Redis AOF setting is not observable with current permissions.'),
                $this->check('redis_appendfsync', ProductionSafetyCheckStatus::Fail, 'Redis appendfsync setting is not observable with current permissions.'),
                $this->check('redis_maxmemory_policy', ProductionSafetyCheckStatus::Fail, 'Redis maxmemory policy is not observable with current permissions.'),
            ];
        }

        return [
            ...$checks,
            $this->check(
                'redis_aof',
                $durability->appendOnly()
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                $durability->appendOnly()
                    ? 'Redis AOF is enabled.'
                    : 'Redis AOF is not enabled.',
            ),
            $this->check(
                'redis_appendfsync',
                $durability->appendFsync() === 'always'
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                $durability->appendFsync() === 'always'
                    ? 'Redis appendfsync is configured as always.'
                    : 'Redis appendfsync is not configured as always.',
            ),
            $this->check(
                'redis_maxmemory_policy',
                $durability->maxmemoryPolicy() === 'noeviction'
                    ? ProductionSafetyCheckStatus::Pass
                    : ProductionSafetyCheckStatus::Fail,
                $durability->maxmemoryPolicy() === 'noeviction'
                    ? 'Redis maxmemory policy is noeviction.'
                    : 'Redis maxmemory policy is not noeviction.',
            ),
        ];
    }

    private function check(
        string $code,
        ProductionSafetyCheckStatus $status,
        string $message,
    ): ProductionSafetyCheck {
        return new ProductionSafetyCheck($code, $status, $message);
    }
}
