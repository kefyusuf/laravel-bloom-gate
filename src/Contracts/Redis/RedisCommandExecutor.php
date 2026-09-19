<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Contracts\Redis;

interface RedisCommandExecutor
{
    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     *
     * @throws Exception\RedisCommandFailed
     */
    public function evaluate(
        string $script,
        array $keys,
        array $arguments,
    ): int;
}
