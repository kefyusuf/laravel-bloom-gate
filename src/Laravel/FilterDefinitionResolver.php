<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;

final readonly class FilterDefinitionResolver
{
    public function __construct(
        private Container $container,
    ) {}

    public function resolve(mixed $definition): FilterDefinition
    {
        if (
            ! is_string($definition)
            || $definition === ''
            || (
                class_exists($definition) === false
                && interface_exists($definition) === false
            )
        ) {
            throw new InvalidConfiguration(
                'Bloom Gate filter definition must be a resolvable class or interface name.',
            );
        }

        try {
            $resolved = $this->container->make($definition);
        } catch (BindingResolutionException $failure) {
            throw new InvalidConfiguration(
                'Bloom Gate filter definition could not be resolved by the Laravel container.',
                0,
                $failure,
            );
        }

        if (! $resolved instanceof FilterDefinition) {
            throw new InvalidConfiguration(
                'Resolved Bloom Gate filter definition must implement FilterDefinition.',
            );
        }

        return $resolved;
    }
}
