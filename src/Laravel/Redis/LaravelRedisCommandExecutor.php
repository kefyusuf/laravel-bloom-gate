<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Redis;

use Illuminate\Redis\Connections\Connection;
use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use ReflectionMethod;
use Throwable;
use UnexpectedValueException;

final readonly class LaravelRedisCommandExecutor implements RedisStructuredCommandExecutor
{
    /**
     * @var list<string>
     */
    private const array OPERATIONAL_FAILURE_TYPES = [
        'RedisException',
        'Predis\\PredisException',
    ];

    public function __construct(
        private Connection $connection,
    ) {}

    public function evaluate(
        string $script,
        array $keys,
        array $arguments,
    ): int {
        $result = $this->executeEval($script, $keys, $arguments);

        if (! is_int($result)) {
            throw new UnexpectedValueException(sprintf(
                'Expected Laravel Redis EVAL to return int, received [%s].',
                get_debug_type($result),
            ));
        }

        return $result;
    }

    public function evaluateStructured(
        string $script,
        array $keys,
        array $arguments,
    ): array {
        $result = $this->executeEval($script, $keys, $arguments);

        if (! is_array($result) || ! array_is_list($result)) {
            throw new UnexpectedValueException(sprintf(
                'Expected Laravel Redis EVAL to return list<string>, received [%s].',
                get_debug_type($result),
            ));
        }

        $structured = [];

        foreach ($result as $index => $value) {
            if (! is_string($value)) {
                throw new UnexpectedValueException(sprintf(
                    'Expected Laravel Redis EVAL list item [%d] to be string, received [%s].',
                    $index,
                    get_debug_type($value),
                ));
            }

            $structured[] = $value;
        }

        return $structured;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function executeEval(
        string $script,
        array $keys,
        array $arguments,
    ): mixed {
        try {
            return $this->invokeEval($script, $keys, $arguments);
        } catch (Throwable $failure) {
            if (! $this->isOperationalRedisFailure($failure)) {
                throw $failure;
            }

            throw new RedisCommandFailed(
                'Laravel Redis EVAL command failed.',
                0,
                $failure,
            );
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function invokeEval(
        string $script,
        array $keys,
        array $arguments,
    ): mixed {
        $parameters = [
            $script,
            count($keys),
            ...$keys,
            ...$arguments,
        ];

        if (method_exists($this->connection, 'eval')) {
            return (new ReflectionMethod($this->connection, 'eval'))
                ->invokeArgs($this->connection, $parameters);
        }

        return $this->connection->command('eval', $parameters);
    }

    private function isOperationalRedisFailure(Throwable $failure): bool
    {
        foreach (self::OPERATIONAL_FAILURE_TYPES as $type) {
            if (is_a($failure, $type)) {
                return true;
            }
        }

        return false;
    }
}
