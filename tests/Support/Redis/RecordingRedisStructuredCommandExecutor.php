<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use RuntimeException;

final class RecordingRedisStructuredCommandExecutor implements RedisStructuredCommandExecutor
{
    /**
     * @var list<list<string>|RedisCommandFailed>
     */
    private array $structuredResponses;

    /**
     * @var list<array{script: string, keys: list<string>, arguments: list<string>}>
     */
    private array $structuredCalls = [];

    /**
     * @param  list<list<string>|RedisCommandFailed>  $structuredResponses
     */
    public function __construct(array $structuredResponses)
    {
        $this->structuredResponses = $structuredResponses;
    }

    public function evaluate(
        string $script,
        array $keys,
        array $arguments,
    ): int {
        throw new RuntimeException('Legacy integer EVAL is not configured for this test executor.');
    }

    public function evaluateStructured(
        string $script,
        array $keys,
        array $arguments,
    ): array {
        $this->structuredCalls[] = [
            'script' => $script,
            'keys' => $keys,
            'arguments' => $arguments,
        ];

        $response = array_shift($this->structuredResponses);

        if ($response === null) {
            throw new RuntimeException('No recorded structured Redis response remains.');
        }

        if ($response instanceof RedisCommandFailed) {
            throw $response;
        }

        return $response;
    }

    /**
     * @return list<array{script: string, keys: list<string>, arguments: list<string>}>
     */
    public function structuredCalls(): array
    {
        return $this->structuredCalls;
    }

    /**
     * @return list<array{mode: 'structured', script: string, keys: list<string>, arguments: list<string>}>
     */
    public function calls(): array
    {
        return array_map(
            static fn (array $call): array => [
                'mode' => 'structured',
                ...$call,
            ],
            $this->structuredCalls,
        );
    }
}
