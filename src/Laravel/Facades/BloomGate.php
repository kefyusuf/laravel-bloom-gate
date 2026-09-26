<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Kefyusuf\BloomGate\Application\ExistenceResult;
use Kefyusuf\BloomGate\Laravel\BloomGateManager;

/**
 * @method static bool exists(string $filter, string|int $value)
 * @method static ExistenceResult existsResult(string $filter, string|int $value)
 * @method static void add(string $filter, string|int $value)
 * @method static void addMany(string $filter, iterable<string|int> $values)
 *
 * @see BloomGateManager
 */
final class BloomGate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BloomGateManager::class;
    }
}
