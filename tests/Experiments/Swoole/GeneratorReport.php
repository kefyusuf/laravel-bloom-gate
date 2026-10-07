<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use InvalidArgumentException;

/** Admits supplied observations only; source authentication and live collection are separate. */
final class GeneratorReport
{
    /** @return array{verdict:string,reasons:list<string>} */
    public static function evaluateCell(mixed $input): array
    {
        try {
            $cell = self::object($input);
            foreach (['unfinished_iterations', 'dropped_iterations', 'errors', 'parity_failures',
                'false_negatives', 'unknown_membership', 'total_errors', 'total_parity_failures',
                'total_false_negatives', 'total_unknown_membership', 'total_delay_mismatches',
                'sql_calls', 'redis_calls'] as $field) {
                self::check(self::integer($cell, $field) === 0, "$field must be zero.");
            }
            self::check(($cell['generator_saturated'] ?? null) === false, 'Generator saturation invalidates qualification.');
            self::check(($cell['window_basis'] ?? null) === 'scenario-start-time', 'The measurement window must use scenario start time.');
            self::check(($cell['path'] ?? null) === 'generator' && self::integer($cell, 'workers') === 4
                && self::integer($cell, 'vus') === 1024 && self::integer($cell, 'offered_rate') === 4800
                && self::integer($cell, 'warmup_seconds') === 30 && self::integer($cell, 'duration_seconds') === 60,
                'The fixed rate, VU and duration envelope must match.');
            $responses = self::integer($cell, 'responses');
            self::check(abs($responses - 288000) <= 1 && self::integer($cell, 'started_iterations') === $responses
                && self::integer($cell, 'completed_iterations') === $responses
                && abs(self::integer($cell, 'total_started_iterations') - 432000) <= 1,
                'Scheduled warmup/measurement counts must match.');
            $elapsed = self::number($cell, 'elapsed_seconds');
            self::check($elapsed >= 60 && $responses / $elapsed >= 4560, 'Generation did not retain throughput including drain.');
            $delay = self::integer($cell, 'controlled_delay_ms');
            self::check(in_array($delay, [0, 25, 100, 150], true), 'Unqualified controlled delay.');
            $p50 = self::number($cell, 'p50_ms');
            $p99 = self::number($cell, 'p99_ms');
            self::check($p50 >= $delay && $p50 <= $p99 && $p99 <= $delay + 50,
                'The controlled receiver exceeded the latency envelope.');
            $receiver = self::object($cell['receiver_delta'] ?? null);
            self::check(self::integer($cell, 'total_started_iterations') === self::integer($receiver, 'started')
                && self::integer($cell, 'total_completed_iterations') === self::integer($receiver, 'completed')
                && self::integer($receiver, 'started') === self::integer($receiver, 'completed')
                && self::integer($receiver, 'failed') === 0, 'Independent receiver counts disagree or contain pending/failed work.');
            $resources = self::object($cell['resources'] ?? null);
            self::check(self::integer($resources, 'samples') >= 3
                && self::number($resources, 'generator_cpu_max') < 360
                && self::number($resources, 'receiver_cpu_max') < 180
                && self::number($resources, 'generator_memory_percent_max') < 80
                && self::number($resources, 'receiver_memory_percent_max') < 80,
                'Measured resource observations must retain headroom.');
            self::check(($resources['generator_oom'] ?? null) === false
                && ($resources['receiver_oom'] ?? null) === false && ($resources['receiver_running'] ?? null) === true
                && self::integer($resources, 'generator_exit') === 0
                && self::integer($resources, 'receiver_restarts') === 0, 'Container failure invalidates qualification.');
            $pids = self::positiveList($cell['worker_pids'] ?? null);
            $traffic = self::positiveList($cell['worker_requests'] ?? null);
            self::check(count(array_unique($pids)) === 4 && array_sum($traffic) === self::integer($receiver, 'started'),
                'Four distinct receiver workers must account for all traffic.');

            return ['verdict' => 'PASS', 'reasons' => []];
        } catch (InvalidArgumentException $failure) {
            return ['verdict' => 'INCONCLUSIVE', 'reasons' => [$failure->getMessage()]];
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        self::check(is_array($value) && ! array_is_list($value), 'An observation object is required.');

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $object */
    private static function integer(array $object, string $field): int
    {
        $value = $object[$field] ?? null;
        self::check(is_int($value) && $value >= 0, "A nonnegative integer $field is required.");

        /** @var int $value */
        return $value;
    }

    /** @param array<string, mixed> $object */
    private static function number(array $object, string $field): float
    {
        $value = $object[$field] ?? null;
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
            throw new InvalidArgumentException("A finite nonnegative $field is required.");
        }

        return (float) $value;
    }

    /** @return list<int> */
    private static function positiveList(mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) !== 4) {
            throw new InvalidArgumentException('Four observed worker values are required.');
        }
        $values = [];
        foreach ($input as $value) {
            if (! is_int($value) || $value <= 0) {
                throw new InvalidArgumentException('Every worker must be alive and receive traffic.');
            }
            $values[] = $value;
        }

        return $values;
    }

    private static function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
