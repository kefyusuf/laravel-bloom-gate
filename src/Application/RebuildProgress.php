<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

enum RebuildProgress
{
    case Advanced;
    case Blocked;
    case Completed;
    case RecoveryRequired;
}
