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

    /**
     * @param  list<mixed>  $parameters
     */
    public function command($method, array $parameters = [])
    {
        if (! is_string($method)) {
            throw new \LogicException('Task 18 diagnostic command method must be a string.');
        }

        $method = strtolower($method);
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
            $encoded = [];

            foreach ($parameters as $value) {
                if (! is_string($value) && ! is_int($value)) {
                    throw new \LogicException(
                        'Task 18 diagnostic command parameters must be scalar strings or integers.',
                    );
                }

                $encoded[] = (string) $value;
            }

            $key .= ':'.implode(':', $encoded);
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
