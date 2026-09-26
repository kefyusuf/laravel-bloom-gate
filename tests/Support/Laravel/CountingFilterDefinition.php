<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Laravel;

use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use LogicException;

final class CountingFilterDefinition implements FilterDefinition
{
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }

    public static function reset(): void
    {
        self::$instances = 0;
    }

    public function normalizer(): ValueNormalizer
    {
        throw new LogicException('Normalizer is not needed by registry resolution tests.');
    }

    public function authoritativeSet(): AuthoritativeSet
    {
        throw new LogicException('Authoritative set is not needed by registry resolution tests.');
    }

    public function consistency(): ConsistencyContract
    {
        return ConsistencyContract::ImmutableV1;
    }
}
