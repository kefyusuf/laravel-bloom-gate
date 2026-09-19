<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;

final readonly class RespRedisCommandExecutor implements RedisCommandExecutor
{
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
    ) {}

    public function evaluate(
        string $script,
        array $keys,
        array $arguments,
    ): int {
        throw new RedisCommandFailed(sprintf(
            'Live Redis executor is not implemented for [%s:%d].',
            $this->host,
            $this->port,
        ));
    }
}
