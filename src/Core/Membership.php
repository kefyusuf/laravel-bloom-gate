<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum Membership
{
    case DefinitelyAbsent;
    case MaybePresent;
    case Bypassed;
}
