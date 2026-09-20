<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

enum ActivationVerificationStatus
{
    case Passed;
    case FalseNegativeDetected;
}
