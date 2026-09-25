<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Throwable;

final class RecordingIlluminateRedisDiagnosticsConnection extends Connection
{
    /**
     * @var array<string, mixed>
     */
    private array $responses;

    /**
     * @var list<array{method: string, parameters: list<mixed>}>
     */
    private array $calls = [];

    /**
     * @param  array<string, mixed>  $responses
     */
    public function __construct(
        array $responses,
        private ?Throwable $failure = null,
    ) {
        $this->responses = $responses;
    }

    public function command($method, array $parameters = [])
    {
        $method = strtolower((string) $method);
        $parameters = array_values($parameters);

        $this->calls[] = [
            'method' => $method,
            'parameters' => $parameters,
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $key = $method;

        if ($parameters !== []) {
            $key .= ':'.implode(':', array_map(
                static fn (mixed $value): string => (string) $value,
                $parameters,
            ));
        }

        return $this->responses[$key] ?? null;
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
     * @return list<array{method: string, parameters: list<mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
