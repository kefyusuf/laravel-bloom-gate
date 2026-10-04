<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel;

use Illuminate\Contracts\Config\Repository;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Core\FilterName;

final readonly class ConfigRuntimeCoordinationRequirement implements RuntimeCoordinationRequirement
{
    public function __construct(private Repository $config) {}

    public function requiresCoordinatedV1(FilterName $name): bool
    {
        $filters = $this->config->get('bloom-gate.filters');
        if (! is_array($filters)) {
            throw new InvalidConfiguration('Bloom Gate filters configuration must be an array.');
        }
        if (! array_key_exists($name->value(), $filters)) {
            return false;
        }
        $filter = $filters[$name->value()];
        if (! is_array($filter)) {
            throw new InvalidConfiguration('Bloom Gate filter configuration must be an array.');
        }
        $mode = $filter['coordination'] ?? null;
        if ($mode !== null && $mode !== 'coordinated-v1') {
            throw new InvalidConfiguration('Bloom Gate coordination must be null or [coordinated-v1].');
        }

        return $mode === 'coordinated-v1';
    }
}
