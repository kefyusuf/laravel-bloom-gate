<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum HealthState
{
    case Healthy;
    case Degraded;
    case Stale;
    case Unavailable;
}
