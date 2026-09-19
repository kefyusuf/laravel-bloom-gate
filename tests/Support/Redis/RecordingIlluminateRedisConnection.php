<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Throwable;

final class RecordingIlluminateRedisConnection extends Connection
{
    /**
     * @var list<array{script: string, numberOfKeys: int, arguments: list<string>}>
     */
    private array $evalCalls = [];

    public function __construct(
        private mixed $result = 0,
        private ?Throwable $failure = null,
    ) {}

    public function eval(
        string $script,
        int $numberOfKeys,
        string ...$arguments,
    ): mixed {
        $this->evalCalls[] = [
            'script' => $script,
            'numberOfKeys' => $numberOfKeys,
            'arguments' => array_values($arguments),
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }

    /**
     * @param  array<array-key, mixed>|string  $channels
     */
    public function createSubscription(
        $channels,
        Closure $callback,
        $method = 'subscribe',
    ): void {}

    /**
     * @return list<array{script: string, numberOfKeys: int, arguments: list<string>}>
     */
    public function evalCalls(): array
    {
        return $this->evalCalls;
    }
}
