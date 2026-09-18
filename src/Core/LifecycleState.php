<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum LifecycleState
{
    case Configured;
    case Building;
    case Shadow;
    case Verified;
    case Active;
    case Retired;
}
