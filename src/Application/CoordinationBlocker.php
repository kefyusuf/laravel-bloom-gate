<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

enum CoordinationBlocker
{
    case None;
    case RuntimeConfigurationMismatch;
    case AdoptionPending;
    case DrainingWriters;
    case InvalidState;
    case DiagnosticsUnavailable;
}
