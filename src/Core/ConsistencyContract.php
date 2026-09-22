<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum ConsistencyContract: string
{
    case ImmutableV1 = 'immutable-v1';
    case PreAddV1 = 'preadd-v1';
}
