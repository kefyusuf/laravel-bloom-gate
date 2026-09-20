<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;

final readonly class RedisKeyspace
{
    private function __construct(
        private string $prefix,
    ) {}

    public static function fromPrefix(string $prefix): self
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('Redis keyspace prefix must be 1 to 64 safe ASCII bytes.');
        }

        return new self($prefix);
    }

    public function stateKey(FilterName $name): string
    {
        return sprintf(
            '%s:{%s}:state',
            $this->prefix,
            $name->value(),
        );
    }

    public function stateStagingKey(FilterName $name): string
    {
        return sprintf(
            '%s:{%s}:state:staging',
            $this->prefix,
            $name->value(),
        );
    }

    public function metaKey(
        FilterName $name,
        FilterVersion $version,
    ): string {
        return sprintf(
            '%s:{%s}:v:%d:meta',
            $this->prefix,
            $name->value(),
            $version->value(),
        );
    }

    public function bitmapKey(
        FilterName $name,
        FilterVersion $version,
    ): string {
        return sprintf(
            '%s:{%s}:v:%d:bf',
            $this->prefix,
            $name->value(),
            $version->value(),
        );
    }
}
