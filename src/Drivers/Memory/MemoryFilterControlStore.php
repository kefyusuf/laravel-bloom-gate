<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;

final class MemoryFilterControlStore implements FilterControlStore
{
    /**
     * @var array<string, FilterControlState>
     */
    private array $states = [];

    public function read(FilterName $name): ?FilterControlState
    {
        return $this->states[$name->value()] ?? null;
    }

    public function compareAndSwap(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void {
        if ($name->equals($next->filterName()) === false) {
            throw new InvalidArgumentException(
                'Control state target filter name must match the snapshot filter name.',
            );
        }

        $key = $name->value();
        $current = $this->states[$key] ?? null;

        if ($current === null) {
            if ($expectedRevision !== null) {
                throw new FilterControlWriteConflict(
                    'Control state does not exist at the expected revision.',
                );
            }

            if ($next->revision()->value() !== 1) {
                throw new InvalidArgumentException(
                    'Initial control state revision must be 1.',
                );
            }

            $this->states[$key] = $next;

            return;
        }

        if (
            $expectedRevision === null
            || $current->revision()->equals($expectedRevision) === false
        ) {
            throw new FilterControlWriteConflict(
                'Control state revision does not match the expected revision.',
            );
        }

        if ($next->revision()->equals($current->revision()->next()) === false) {
            throw new InvalidArgumentException(
                'Updated control state revision must advance exactly once.',
            );
        }

        $this->states[$key] = $next;
    }
}
