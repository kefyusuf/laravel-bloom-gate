<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

enum LeaseResolutionResult
{
    case Released;
    case AlreadyReleased;
}
