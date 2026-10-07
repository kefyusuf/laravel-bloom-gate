<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use InvalidArgumentException;

/** Validates supplied evidence; it neither runs a benchmark nor proves its provenance. */
final class MeasurementReport
{
    /** @return array{verdict: string, reasons: list<string>} */
    public static function evaluate(mixed $input): array
    {
        try {
            return self::screen(self::object($input));
        } catch (InvalidArgumentException $failure) {
            return ['verdict' => 'INCONCLUSIVE', 'reasons' => [$failure->getMessage()]];
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{verdict: string, reasons: list<string>}
     */
    private static function screen(array $report): array
    {
        $identity = self::object($report['identity'] ?? null);
        self::identity($identity);
        $capacity = self::count($report, 'sql_capacity');
        $rate = (int) floor($capacity * 0.8);
        self::require($rate > 0, 'SQL capacity must permit a positive screen rate.');
        $cells = $report['cells'] ?? null;
        self::require(is_array($cells) && array_is_list($cells) && count($cells) === 18, 'Exactly eighteen ordered screen cells are required.');
        /** @var list<mixed> $cells */
        $first = self::object($cells[0]);
        $warmup = self::number($first, 'warmup_seconds');
        $duration = self::number($first, 'duration_seconds');
        $failures = [];
        foreach ([1, 2, 3] as $block) {
            $rotation = match ($block) {
                1 => ['direct', 'bypass', 'redis', 'shared'],
                2 => ['redis', 'shared', 'direct', 'bypass'],
                3 => ['shared', 'direct', 'bypass', 'redis'],
            };
            $paired = [];
            foreach (['control-before', ...$rotation, 'control-after'] as $offset => $path) {
                $cell = self::object($cells[($block - 1) * 6 + $offset]);
                self::require(($cell['path'] ?? null) === $path && self::count($cell, 'block') === $block, 'Screen cells must use the declared paired rotation.');
                self::require(self::object($cell['identity'] ?? null) === $identity, 'Source, runtime, dependencies and dataset must match every cell.');
                self::cell($cell, $rate);
                if (in_array($path, ['direct', 'control-before', 'control-after'], true)) {
                    self::require(self::throughput($cell) >= $rate * 0.95, 'Direct SQL did not sustain the calibrated screen rate.');
                }
                self::require(self::number($cell, 'warmup_seconds') === $warmup
                    && self::number($cell, 'duration_seconds') === $duration, 'Every path must use the same warmup and measured window.');
                $paired[$path] = $cell;
            }
            $before = self::number(self::object($paired['control-before'] ?? null), 'p99_ms');
            $after = self::number(self::object($paired['control-after'] ?? null), 'p99_ms');
            self::require(abs($after - $before) / $before <= 0.1 + 1e-12, 'Direct SQL p99 control drift exceeds ten percent.');
            foreach (['direct', 'redis'] as $comparison) {
                $shared = self::object($paired['shared'] ?? null);
                $compared = self::object($paired[$comparison] ?? null);
                if (self::number($shared, 'p99_ms') > self::number($compared, 'p99_ms') * 0.8) {
                    $failures[] = "Block $block: shared p99 does not improve $comparison by twenty percent.";
                }
                if (self::throughput($shared) < self::throughput($compared) * 0.95) {
                    $failures[] = "Block $block: shared throughput is below ninety-five percent of $comparison.";
                }
            }
        }

        return ['verdict' => $failures === [] ? 'GO' : 'NO-GO', 'reasons' => $failures];
    }

    /** @param array<string, mixed> $identity */
    private static function identity(array $identity): void
    {
        foreach (['source_ref' => 40, 'source_digest' => 64, 'lock_digest' => 64, 'dataset_digest' => 64] as $field => $length) {
            $value = $identity[$field] ?? null;
            self::require(is_string($value) && preg_match('/\A[0-9a-f]{'.$length.'}\z/', $value) === 1, "Valid $field is required.");
        }
        $images = self::object($identity['images'] ?? null);
        foreach (['php', 'mysql', 'redis', 'k6'] as $image) {
            $value = $images[$image] ?? null;
            self::require(is_string($value) && preg_match('/\Asha256:[0-9a-f]{64}\z/', $value) === 1, "Pinned $image image digest is required.");
        }
        self::require(is_string($identity['runtime'] ?? null) && trim($identity['runtime']) !== '', 'Runtime versions are required.');
        foreach (['sealed', 'integrity_checks', 'redis_apcu', 'redis_evalsha'] as $field) {
            self::require(($identity[$field] ?? null) === true, "$field must be enabled and observed.");
        }
        self::require(($identity['sql_connection'] ?? null) === 'worker-local-reused', 'Equal worker-local SQL connection reuse is required.');
        self::require(($identity['redis_profile'] ?? null) === 'standalone-primary-durable-v1', 'The supported live Redis authorization profile is required.');
    }

    /** @param array<string, mixed> $cell */
    private static function cell(array $cell, int $rate): void
    {
        self::require(self::count($cell, 'workers') === 4 && self::count($cell, 'offered_rate') === $rate, 'Every path must use four workers and eighty percent SQL capacity.');
        self::require(self::count($cell, 'absent_percent') === 90, 'The initial screen requires ninety percent absent input.');
        self::require(self::number($cell, 'warmup_seconds') >= 30 && self::number($cell, 'duration_seconds') >= 60, 'Warmup and measured duration are below the minimum.');
        $responses = self::count($cell, 'responses');
        $present = self::count($cell, 'present_responses');
        $absent = self::count($cell, 'absent_responses');
        self::require($responses >= 10000, 'Each measured cell requires at least ten thousand responses.');
        self::require($responses <= ceil($rate * self::number($cell, 'duration_seconds')) + 1, 'Response counts exceed the offered measured load.');
        $started = self::count($cell, 'started_iterations');
        $completed = self::count($cell, 'completed_iterations');
        $unfinished = self::count($cell, 'unfinished_iterations');
        self::require(abs($started - $rate * self::number($cell, 'duration_seconds')) <= 1, 'Started iterations must match the offered measured load.');
        self::require($completed === $responses && $started === $completed + $unfinished && $unfinished === 0, 'Every started measured iteration must complete with an accounted response.');
        self::require($present + $absent === $responses && abs($present - $responses * 0.1) <= 1, 'Measured input counts must account for every response and the declared distribution.');
        foreach (['errors', 'parity_failures', 'false_negatives', 'unknown_membership', 'dropped_iterations'] as $field) {
            self::require(self::count($cell, $field) === 0, "Nonzero $field invalidates the screen.");
        }
        self::require(($cell['generator_saturated'] ?? null) === false, 'Generator saturation must be explicitly absent.');
        $p50 = self::number($cell, 'p50_ms');
        $p95 = self::number($cell, 'p95_ms');
        $p99 = self::number($cell, 'p99_ms');
        self::require($p50 > 0 && $p50 <= $p95 && $p95 <= $p99, 'HTTP latency quantiles must be positive and ordered.');
        $rps = self::number($cell, 'rps');
        self::require($rps > 0, 'Reported measured RPS must be positive.');
        $elapsed = self::number($cell, 'elapsed_seconds');
        self::require($elapsed >= self::number($cell, 'duration_seconds'), 'Elapsed time must include the offered window and completion drain.');
        self::require(abs($rps - $responses / $elapsed) <= 0.01, 'Measured RPS must agree with response counts and elapsed time including drain.');
    }

    /** @param array<string, mixed> $cell */
    private static function throughput(array $cell): float
    {
        return self::count($cell, 'responses') / self::number($cell, 'elapsed_seconds');
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new InvalidArgumentException('An evidence object is missing or malformed.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Evidence object keys must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
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

    /** @param array<string, mixed> $object */
    private static function count(array $object, string $field): int
    {
        $value = $object[$field] ?? null;
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("A nonnegative integer $field is required.");
        }

        return $value;
    }

    /** @phpstan-assert true $condition */
    private static function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($reason);
        }
    }
}
