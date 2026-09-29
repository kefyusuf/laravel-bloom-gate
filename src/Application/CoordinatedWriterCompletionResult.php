<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

enum CoordinatedWriterCompletionResult
{
    case Released;
    case CleanupUncertain;
}
