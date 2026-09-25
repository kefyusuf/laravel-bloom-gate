<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class ProductionSafetySettings
{
    /**
     * @var list<FilterName>
     */
    private array $filterNames;

    /**
     * @param  list<FilterName>  $filterNames
     */
    public function __construct(
        private string $driver,
        private ?string $trustedNegativeProfile,
        private string $keyspacePrefix,
        array $filterNames,
    ) {
        if ($this->driver !== 'memory' && $this->driver !== 'redis') {
            throw new InvalidArgumentException(
                'Production safety driver must be memory or redis.',
            );
        }

        if ($this->keyspacePrefix === '') {
            throw new InvalidArgumentException(
                'Production safety keyspace prefix cannot be empty.',
            );
        }

        $this->filterNames = $filterNames;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function trustedNegativeProfile(): ?string
    {
        return $this->trustedNegativeProfile;
    }

    public function keyspacePrefix(): string
    {
        return $this->keyspacePrefix;
    }

    /**
     * @return list<FilterName>
     */
    public function filterNames(): array
    {
        return $this->filterNames;
    }
}
