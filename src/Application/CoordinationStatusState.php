<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

enum CoordinationStatusState
{
    case Unadopted;
    case AdoptionPending;
    case Adopted;
    case Invalid;
    case Unavailable;
}
