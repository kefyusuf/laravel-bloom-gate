<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum ProbeAlgorithm: string
{
    case Sha256DoubleHashV1 = 'sha256-double-hash-v1';
}
