<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use InvalidArgumentException;

/** Admits supplied observations only; source authentication and live collection are separate. */
final class GeneratorReport
{
    /** @return array{verdict:string,reasons:list<string>} */
    public static function evaluateNegativeControl(mixed $input): array
    {
        try {
            $run = self::object($input);
            $identity = self::object($run['identity'] ?? null);
            self::check(is_string($identity['source_ref'] ?? null)
                && preg_match('/^[a-f0-9]{40}$/D', $identity['source_ref']) === 1,
                'A frozen source identity is required.');
            foreach (['receiver_image', 'k6_image_id'] as $field) {
                self::check(is_string($identity[$field] ?? null)
                    && preg_match('/^sha256:[a-f0-9]{64}$/D', $identity[$field]) === 1,
                    'Frozen receiver/generator image identities are required.');
            }
            $sources = self::object($identity['sources'] ?? null);
            foreach (['load.js', 'Dockerfile'] as $file) {
                self::check(is_string($sources[$file] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $sources[$file]) === 1,
                    'Frozen load and runtime definition digests are required.');
            }
            $provenance = self::object($identity['k6_provenance'] ?? null);
            self::check(($provenance['image_id'] ?? null) === $identity['k6_image_id']
                && ($provenance['upstream_revision'] ?? null) === '5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb'
                && ($provenance['runtime_source_sha256'] ?? null) === 'e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce'
                && ($provenance['instrumented_clock'] ?? null) === false
                && ($provenance['go_version'] ?? null) === 'go1.23.7',
                'The verified patched generator image provenance is required.');
            foreach (['binary_sha256', 'patch_sha256'] as $field) {
                self::check(is_string($provenance[$field] ?? null)
                    && preg_match('/^[a-f0-9]{64}$/D', $provenance[$field]) === 1,
                    'Generator binary and patch digests are required.');
            }
            self::check(($sources['K6Boundary/arrival-slot-boundary.patch'] ?? null) === $provenance['patch_sha256'],
                'The installed generator patch must match the frozen sources.');
            self::check(self::integer($identity, 'generator_cpus') === 4
                && self::integer($identity, 'generator_memory_bytes') === 4294967296
                && self::integer($identity, 'receiver_cpus') === 2
                && self::integer($identity, 'receiver_memory_bytes') === 268435456
                && self::integer($identity, 'fixed_vus') === 1024 && self::integer($identity, 'workers') === 4,
                'Frozen qualification budgets must match.');
            $negative = self::object($run['negative_control'] ?? null);
            $control = self::object($negative['cell'] ?? null);
            self::check(($control['identity'] ?? null) === $identity
                && self::integer($control, 'offered_rate') === 1200 && self::integer($control, 'vus') === 128
                && self::integer($control, 'controlled_delay_ms') === 150
                && self::integer($control, 'warmup_seconds') === 2 && self::integer($control, 'duration_seconds') === 5
                && self::integer($control, 'dropped_iterations') > 0,
                'The same-source fixed-VU deficit must lose scheduled work.');
            $rejection = self::evaluateCell($control);
            self::check($rejection['reasons'] === ['dropped_iterations must be zero.'],
                'The negative control must be rejected for dropped work.');
            foreach (['unfinished_iterations', 'errors', 'parity_failures', 'false_negatives', 'unknown_membership',
                'total_errors', 'total_parity_failures', 'total_false_negatives', 'total_unknown_membership',
                'total_delay_mismatches', 'sql_calls', 'redis_calls'] as $field) {
                self::check(self::integer($control, $field) === 0, 'Negative-control safety failures invalidate qualification.');
            }
            $controlTotal = self::integer($control, 'total_started_iterations');
            $controlReceiver = self::object($control['receiver_delta'] ?? null);
            self::check($controlTotal > 0 && self::integer($control, 'total_completed_iterations') === $controlTotal
                && self::integer($control, 'builtin_iterations') === $controlTotal
                && self::integer($control, 'builtin_http_requests') === $controlTotal
                && self::integer($controlReceiver, 'started') === $controlTotal
                && self::integer($controlReceiver, 'completed') === $controlTotal
                && self::integer($controlReceiver, 'failed') === 0
                && abs($controlTotal + self::integer($control, 'dropped_iterations') - 8400) <= 1,
                'Negative-control completed and dropped work must be independently accounted for.');
            self::check(is_string($negative['raw'] ?? null)
                && preg_match('/^[a-z0-9-]+\.json$/D', $negative['raw']) === 1,
                'The negative-control raw artifact is required.');

            return ['verdict' => 'VALID_NEGATIVE_CONTROL', 'reasons' => []];
        } catch (InvalidArgumentException $failure) {
            return ['verdict' => 'INCONCLUSIVE', 'reasons' => [$failure->getMessage()]];
        }
    }

    /** @return array{verdict:string,reasons:list<string>} */
    public static function evaluateQualification(mixed $input): array
    {
        try {
            $controlResult = self::evaluateNegativeControl($input);
            self::check($controlResult['verdict'] === 'VALID_NEGATIVE_CONTROL', implode(' ', $controlResult['reasons']));
            $run = self::object($input);
            $identity = self::object($run['identity'] ?? null);
            $negative = self::object($run['negative_control'] ?? null);
            $profiles = $run['profiles'] ?? null;
            self::check(is_array($profiles) && array_is_list($profiles) && count($profiles) === 12,
                'All four delays in three blocks are required.');
            $cells = [];
            $artifacts = [];
            $rawControl = $negative['raw'] ?? null;
            self::check(is_string($rawControl), 'The negative-control raw artifact is required.');
            $artifacts[$rawControl] = true;
            foreach ($profiles as $profileInput) {
                $profile = self::object($profileInput);
                $cell = self::object($profile['cell'] ?? null);
                self::check(($cell['identity'] ?? null) === $identity, 'Every cell must use the same frozen identity.');
                $block = self::integer($cell, 'block');
                $delay = self::integer($cell, 'controlled_delay_ms');
                $key = "$block:$delay";
                $raw = $profile['raw'] ?? null;
                self::check(in_array($block, [1, 2, 3], true) && ! isset($cells[$key])
                    && is_string($raw) && preg_match('/^[a-z0-9-]+\.json$/D', $raw) === 1 && ! isset($artifacts[$raw]),
                    'Distinct block/delay cells and raw artifacts are required.');
                $result = self::evaluateCell($cell);
                self::check($result['verdict'] === 'PASS', implode(' ', $result['reasons']));
                $cells[$key] = true;
                $artifacts[$raw] = true;
            }

            return ['verdict' => 'PASS', 'reasons' => []];
        } catch (InvalidArgumentException $failure) {
            return ['verdict' => 'INCONCLUSIVE', 'reasons' => [$failure->getMessage()]];
        }
    }

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
            self::check(($cell['measurement_contract'] ?? null) === 'scheduled-completeness-actual-window-v1'
                && ($cell['execution_topology'] ?? null) === 'single-local-constant-arrival',
                'The versioned single-local measurement contract is required.');
            self::check(($cell['path'] ?? null) === 'generator' && self::integer($cell, 'workers') === 4
                && self::integer($cell, 'vus') === 1024 && self::integer($cell, 'offered_rate') === 4800
                && self::integer($cell, 'warmup_seconds') === 30 && self::integer($cell, 'duration_seconds') === 60,
                'The fixed rate, VU and duration envelope must match.');
            $responses = self::integer($cell, 'responses');
            $total = self::integer($cell, 'total_started_iterations');
            $audit = self::object($cell['window_audit'] ?? null);
            $scheduled = self::integer($audit, 'scheduled_started');
            self::check(abs($scheduled - 288000) <= 1 && self::integer($audit, 'scheduled_completed') === $scheduled
                && abs($total - 432000) <= 1 && self::integer($cell, 'total_completed_iterations') === $total
                && self::integer($cell, 'builtin_iterations') === $total
                && self::integer($cell, 'builtin_http_requests') === $total,
                'Scheduled warmup/measurement counts must match.');
            self::check(self::integer($cell, 'started_iterations') === $responses
                && self::integer($cell, 'completed_iterations') === $responses
                && $responses <= $total, 'Actual-window starts, completions and responses must match.');
            $warmupIn = self::integer($audit, 'warmup_entered');
            $extraIn = self::integer($audit, 'extra_entered');
            $outside = self::integer($audit, 'scheduled_outside');
            self::check(($audit['index_interpretation'] ?? null) === 'zero-drop-local-only'
                && $warmupIn <= 144000 && $warmupIn <= $total - $scheduled
                && $extraIn <= max(0, $total - 432000) && $outside <= $scheduled
                && self::integer($audit, 'scheduled_early') + self::integer($audit, 'scheduled_late') === $outside
                && $responses === $scheduled + $warmupIn + $extraIn - $outside,
                'Actual-window cohort crossings must reconcile exactly.');
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

    /** @phpstan-assert true $condition */
    private static function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
