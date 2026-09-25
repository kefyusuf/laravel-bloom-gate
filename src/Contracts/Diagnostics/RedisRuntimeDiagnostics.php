<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts\Diagnostics;

use Kefyusuf\BloomGate\Core\RedisDurabilitySettings;
use Kefyusuf\BloomGate\Core\RedisRuntimeInfo;

interface RedisRuntimeDiagnostics
{
    /**
     * @throws Exception\RedisDiagnosticsUnavailable
     */
    public function runtime(): RedisRuntimeInfo;

    /**
     * @throws Exception\RedisDiagnosticsUnavailable
     */
    public function durability(): RedisDurabilitySettings;
}
