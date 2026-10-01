<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

enum CoordinationRecovery
{
    case None;
    case RebuildRecoveryRequired;
    case PromotionSynchronizationPending;
    case AbortRequested;
    case AbortDraining;
    case AbortFinalizationPending;
}
