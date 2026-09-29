<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Core\FilterName;

final readonly class Wu05RuntimeCoordinationRequirement implements RuntimeCoordinationRequirement
{
    public function __construct(
        private bool $required,
    ) {}

    public function requiresCoordinatedV1(FilterName $name): bool
    {
        return $this->required;
    }
}
