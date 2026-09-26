<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel;

use Illuminate\Contracts\Config\Repository;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownFilter;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\FilterName;

final readonly class ConfigFilterRegistry implements FilterRegistry
{
    public function __construct(
        private Repository $config,
        private FilterDefinitionResolver $definitionResolver,
    ) {}

    public function globalQueryOptimizationEnabled(): bool
    {
        $enabled = $this->config->get('bloom-gate.enabled');

        if (! is_bool($enabled)) {
            throw new InvalidConfiguration(
                'Bloom Gate global enabled configuration must be boolean.',
            );
        }

        return $enabled;
    }

    public function get(FilterName $name): RegisteredFilter
    {
        $filters = $this->config->get('bloom-gate.filters');

        if (! is_array($filters)) {
            throw new InvalidConfiguration(
                'Bloom Gate filters configuration must be an array.',
            );
        }

        $key = $name->value();

        if (! array_key_exists($key, $filters)) {
            throw new UnknownFilter(
                sprintf('Bloom Gate filter [%s] is not registered.', $key),
            );
        }

        $configuration = $filters[$key];

        if (! is_array($configuration)) {
            throw new InvalidConfiguration(
                sprintf('Bloom Gate filter [%s] configuration must be an array.', $key),
            );
        }

        $enabled = $configuration['enabled'] ?? null;
        $capacity = $configuration['capacity'] ?? null;
        $falsePositiveRate = $configuration['false_positive_rate'] ?? null;

        if (! is_bool($enabled)) {
            throw new InvalidConfiguration(
                sprintf('Bloom Gate filter [%s] enabled configuration must be boolean.', $key),
            );
        }

        if (! is_int($capacity) || $capacity < 1) {
            throw new InvalidConfiguration(
                sprintf('Bloom Gate filter [%s] capacity must be a positive integer.', $key),
            );
        }

        if (
            ! is_float($falsePositiveRate)
            || ! is_finite($falsePositiveRate)
            || $falsePositiveRate <= 0.0
            || $falsePositiveRate >= 1.0
        ) {
            throw new InvalidConfiguration(
                sprintf(
                    'Bloom Gate filter [%s] false-positive rate must be a finite float greater than 0 and less than 1.',
                    $key,
                ),
            );
        }

        $definition = $this->definitionResolver->resolve(
            $configuration['definition'] ?? null,
        );

        return new RegisteredFilter(
            name: $name,
            definition: $definition,
            queryOptimizationEnabled: $enabled,
            capacity: $capacity,
            falsePositiveRate: $falsePositiveRate,
        );
    }
}
