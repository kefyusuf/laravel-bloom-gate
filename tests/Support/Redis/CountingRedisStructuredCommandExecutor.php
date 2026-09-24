<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;

final class CountingRedisStructuredCommandExecutor implements RedisStructuredCommandExecutor
{
    private int $integerCalls = 0;

    private int $structuredCalls = 0;

    public function __construct(
        private RedisStructuredCommandExecutor $inner,
    ) {}

    public function evaluate(
        string $script,
        array $keys,
        array $arguments,
    ): int {
        $this->integerCalls++;

        return $this->inner->evaluate($script, $keys, $arguments);
    }

    public function evaluateStructured(
        string $script,
        array $keys,
        array $arguments,
    ): array {
        $this->structuredCalls++;

        return $this->inner->evaluateStructured($script, $keys, $arguments);
    }

    public function integerCalls(): int
    {
        return $this->integerCalls;
    }

    public function structuredCalls(): int
    {
        return $this->structuredCalls;
    }
}
