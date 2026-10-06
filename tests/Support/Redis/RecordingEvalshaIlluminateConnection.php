<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Throwable;

class RecordingEvalshaIlluminateConnection extends Connection
{
    /** @var list<array{script: string, numberOfKeys: int, arguments: list<string>}> */
    private array $evalCalls = [];

    /** @var list<array{hash: string, numberOfKeys: int, arguments: list<string>}> */
    private array $shaCalls = [];

    public function __construct(
        private mixed $result = 0,
        private ?Throwable $failure = null,
        private bool $scriptCached = false,
        private ?Throwable $shaFailure = null,
    ) {}

    public function eval(string $script, int $numberOfKeys, string ...$arguments): mixed
    {
        $this->evalCalls[] = ['script' => $script, 'numberOfKeys' => $numberOfKeys, 'arguments' => array_values($arguments)];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }

    public function evalsha(string $hash, int $numberOfKeys, string ...$arguments): mixed
    {
        $this->shaCalls[] = ['hash' => $hash, 'numberOfKeys' => $numberOfKeys, 'arguments' => array_values($arguments)];
        if ($this->shaFailure !== null) {
            throw $this->shaFailure;
        }
        if (! $this->scriptCached) {
            if (! class_exists('RedisException', false)) {
                class_alias(FakeRedisClientException::class, 'RedisException');
            }
            throw new \RedisException('NOSCRIPT No matching script. Please use EVAL.');
        }

        return $this->result;
    }

    /** @return list<array{script: string, numberOfKeys: int, arguments: list<string>}> */
    public function evalCalls(): array
    {
        return $this->evalCalls;
    }

    /** @return list<array{hash: string, numberOfKeys: int, arguments: list<string>}> */
    public function shaCalls(): array
    {
        return $this->shaCalls;
    }

    /** @param array<array-key, mixed>|string $channels */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}
}
