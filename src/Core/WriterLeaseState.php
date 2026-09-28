<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum WriterLeaseState
{
    case Acquired;
    case Prepared;
    case Released;
}
