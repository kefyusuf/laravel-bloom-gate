<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

enum CoordinationOwnership
{
    case Unadopted;
    case AdoptionPending;
    case Adopted;
    case Invalid;
}
