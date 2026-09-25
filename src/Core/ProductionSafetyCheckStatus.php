<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

enum ProductionSafetyCheckStatus
{
    case Pass;
    case Warn;
    case Fail;
    case NotEnabled;

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'PASS',
            self::Warn => 'WARN',
            self::Fail => 'FAIL',
            self::NotEnabled => 'NOT_ENABLED',
        };
    }
}
