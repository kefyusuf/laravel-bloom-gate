<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts\Redis;

interface RedisStructuredCommandExecutor extends RedisCommandExecutor
{
    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     * @return list<string>
     *
     * @throws Exception\RedisCommandFailed
     */
    public function evaluateStructured(
        string $script,
        array $keys,
        array $arguments,
    ): array;
}
