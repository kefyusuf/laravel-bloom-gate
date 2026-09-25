<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\FilterControlState;

final readonly class ManagedFilterBuildResult
{
    public function __construct(
        private FilterControlState $state,
        private BloomLayout $layout,
        private int $processedCount,
    ) {
        if ($this->processedCount < 0) {
            throw new InvalidArgumentException(
                'Managed Bloom build processed count cannot be negative.',
            );
        }
    }

    public function state(): FilterControlState
    {
        return $this->state;
    }

    public function layout(): BloomLayout
    {
        return $this->layout;
    }

    public function processedCount(): int
    {
        return $this->processedCount;
    }
}
