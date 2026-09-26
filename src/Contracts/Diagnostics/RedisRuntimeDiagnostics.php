<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts\Diagnostics;

use Kefyusuf\BloomGate\Core\RedisDurabilitySettings;
use Kefyusuf\BloomGate\Core\RedisRuntimeInfo;

interface RedisRuntimeDiagnostics
{
    /**
     * @throws Exception\RedisDiagnosticsUnavailable
     * @throws Exception\RedisDiagnosticsInvalid
     */
    public function runtime(): RedisRuntimeInfo;

    /**
     * @throws Exception\RedisDiagnosticsUnavailable
     * @throws Exception\RedisDiagnosticsInvalid
     */
    public function durability(): RedisDurabilitySettings;
}
