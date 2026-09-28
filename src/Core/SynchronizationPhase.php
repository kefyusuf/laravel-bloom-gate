<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum SynchronizationPhase
{
    case Steady;
    case DrainingPreReconcile;
    case Reconciling;
    case ReadyToPromote;
    case DrainingPostPromotion;
    case AbortRequested;
    case DrainingAbort;
}
