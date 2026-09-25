<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\ProductionSafetyConfiguration;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\ProductionSafetySettings;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Laravel\Redis\RedisTrustedNegativeProfileResolver;

final readonly class ConfigProductionSafetyConfiguration implements ProductionSafetyConfiguration
{
    public function __construct(
        private Repository $config,
        private RedisTrustedNegativeProfileResolver $profiles,
    ) {}

    public function resolve(): ProductionSafetySettings
    {
        $enabled = $this->config->get('bloom-gate.enabled');

        if (! is_bool($enabled)) {
            throw new InvalidConfiguration(
                'Bloom Gate global enabled configuration must be boolean.',
            );
        }

        $driver = $this->config->get('bloom-gate.default');

        if ($driver !== 'memory' && $driver !== 'redis') {
            throw new InvalidConfiguration(
                'Bloom Gate default driver must be memory or redis.',
            );
        }

        $connection = $this->config->get(
            'bloom-gate.drivers.redis.connection',
        );

        if (! is_string($connection) || $connection === '') {
            throw new InvalidConfiguration(
                'Bloom Gate Redis connection configuration must be a non-empty string.',
            );
        }

        $prefix = $this->config->get('bloom-gate.keyspace.prefix');

        if (! is_string($prefix) || $prefix === '') {
            throw new InvalidConfiguration(
                'Bloom Gate Redis keyspace prefix must be a non-empty string.',
            );
        }

        try {
            RedisKeyspace::fromPrefix($prefix);
        } catch (InvalidArgumentException $failure) {
            throw new InvalidConfiguration(
                'Bloom Gate Redis keyspace prefix is invalid.',
                0,
                $failure,
            );
        }

        $chunkSize = $this->config->get('bloom-gate.build.chunk_size');

        if (! is_int($chunkSize) || $chunkSize < 1) {
            throw new InvalidConfiguration(
                'Bloom Gate build chunk size must be a positive integer.',
            );
        }

        $configured = $this->config->get('bloom-gate.filters');

        if (! is_array($configured)) {
            throw new InvalidConfiguration(
                'Bloom Gate filters configuration must be an array.',
            );
        }

        $names = [];

        foreach (array_keys($configured) as $name) {
            if (! is_string($name)) {
                throw new InvalidConfiguration(
                    'Bloom Gate configured filter names must be strings.',
                );
            }

            try {
                $names[] = FilterName::fromString($name);
            } catch (InvalidArgumentException $failure) {
                throw new InvalidConfiguration(
                    'Bloom Gate configured filter name is invalid.',
                    0,
                    $failure,
                );
            }
        }

        return new ProductionSafetySettings(
            driver: $driver,
            trustedNegativeProfile: $this->profiles->resolve(),
            keyspacePrefix: $prefix,
            filterNames: $names,
        );
    }
}
