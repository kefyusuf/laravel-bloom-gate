<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum AuthoritativeOutcome
{
    case Committed;
    case RolledBack;
    case Unknown;
}
