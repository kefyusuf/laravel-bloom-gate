<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use RuntimeException;

final class RecordingRedisCommandExecutor implements RedisCommandExecutor
{
    /**
     * @var list<int|RedisCommandFailed>
     */
    private array $responses;

    /**
     * @var list<array{script: string, keys: list<string>, arguments: list<string>}>
     */
    private array $calls = [];

    /**
     * @param  list<int|RedisCommandFailed>  $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function evaluate(
        string $script,
        array $keys,
        array $arguments,
    ): int {
        $this->calls[] = [
            'script' => $script,
            'keys' => $keys,
            'arguments' => $arguments,
        ];

        $response = array_shift($this->responses);

        if ($response === null) {
            throw new RuntimeException('No recorded Redis response remains.');
        }

        if ($response instanceof RedisCommandFailed) {
            throw $response;
        }

        return $response;
    }

    /**
     * @return list<array{script: string, keys: list<string>, arguments: list<string>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
