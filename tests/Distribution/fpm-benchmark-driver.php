<?php

declare(strict_types=1);

/** @phpstan-assert true $condition */
function fpmDriverCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param  array<string, int|string>  $parameters
 * @return array<string, mixed>
 */
function fpmRequest(string $url, array $parameters): array
{
    $started = hrtime(true);
    $json = file_get_contents($url.'?'.http_build_query($parameters), false,
        stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]));
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    fpmDriverCheck(is_string($json), 'FPM HTTP request did not return a response.');
    $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    fpmDriverCheck(is_array($report) && ($report['status'] ?? null) === 'passed',
        'FPM fixture failed: '.(is_array($report) && is_string($report['message'] ?? null) ? $report['message'] : 'invalid response'));
    fpmDriverCheck(($report['sapi'] ?? null) === 'fpm-fcgi' && ($report['apcu_enabled'] ?? null) === true,
        'Real FPM with enabled APCu is required.');
    $result = [];
    foreach ($report as $key => $value) {
        fpmDriverCheck(is_string($key), 'FPM report keys must be strings.');
        $result[$key] = $value;
    }
    $result['http_elapsed_ms'] = $elapsed;

    return $result;
}

/** @param array<string, mixed> $report */
function fpmAssertQuery(array $report, int $calls, int $sql, string $membership): void
{
    $executor = $report['executor'] ?? null;
    $actual = is_array($executor) && is_int($executor['calls'] ?? null) ? (string) $executor['calls'] : 'missing';
    fpmDriverCheck(is_array($executor) && ($executor['calls'] ?? null) === $calls,
        'Unexpected FPM Redis executor count; expected '.$calls.', actual '.$actual.'.');
    fpmDriverCheck(($report['authoritative_queries'] ?? null) === $sql
        && ($report['answers'] ?? null) === [false] && ($report['memberships'] ?? null) === [$membership],
        'FPM query did not meet membership/SQL/answer expectations.');
}

/** @param list<float> $samples */
function fpmMedian(array $samples): float
{
    sort($samples, SORT_NUMERIC);

    return $samples[2];
}

/** @return array<string, mixed> */
function runFpmBenchmark(string $url, string $engine): array
{
    fpmDriverCheck(filter_var($url, FILTER_VALIDATE_URL) !== false
        && in_array($engine, ['mysql', 'pgsql'], true), 'Usage: php fpm-benchmark-driver.php <http-url> <mysql|pgsql>');
    $run = bin2hex(random_bytes(8));
    $base = ['database' => $engine, 'run' => $run];
    $setup = fpmRequest($url, $base + ['action' => 'setup']);
    $miss = $setup['definite_miss'] ?? null;
    fpmDriverCheck(is_string($miss), 'Setup did not return a deterministic definite miss.');
    $query = $base + ['action' => 'query', 'value' => $miss, 'cache' => 1];
    $cold = fpmRequest($url, $query);
    fpmAssertQuery($cold, 3, 0, 'DefinitelyAbsent');
    $warm = fpmRequest($url, $query);
    // This fails on the previous implementation: process-local hints cannot survive separate FPM HTTP requests.
    fpmAssertQuery($warm, 1, 0, 'DefinitelyAbsent');
    fpmDriverCheck($cold['package_reference'] === $warm['package_reference'], 'Installed package reference changed between requests.');

    fpmRequest($url, $base + ['action' => 'rebuild']);
    $retried = fpmRequest($url, $query);
    fpmAssertQuery($retried, 4, 0, 'DefinitelyAbsent');
    $retryGroups = $retried['executor'];
    fpmDriverCheck(is_array($retryGroups) && is_array($retryGroups['groups'] ?? null)
        && is_array($retryGroups['groups']['probe'] ?? null)
        && ($retryGroups['groups']['probe']['calls'] ?? null) === 2,
        'Changed-control retry must perform exactly two atomic probes.');
    $retryWarm = fpmRequest($url, $query);
    fpmAssertQuery($retryWarm, 1, 0, 'DefinitelyAbsent');

    fpmRequest($url, $base + ['action' => 'metadata-break']);
    try {
        $metadata = fpmRequest($url, $query);
        fpmAssertQuery($metadata, 1, 1, 'Bypassed');
    } finally {
        fpmRequest($url, $base + ['action' => 'metadata-restore']);
    }
    $afterMetadata = fpmRequest($url, $query);
    fpmAssertQuery($afterMetadata, 3, 0, 'DefinitelyAbsent');

    fpmRequest($url, $base + ['action' => 'health-break']);
    try {
        $health = fpmRequest($url, $query);
        fpmAssertQuery($health, 2, 1, 'Bypassed');
    } finally {
        fpmRequest($url, $base + ['action' => 'health-restore']);
    }
    $afterHealth = fpmRequest($url, $query);
    fpmAssertQuery($afterHealth, 3, 0, 'DefinitelyAbsent');

    fpmRequest($url, $base + ['action' => 'preadd', 'value' => $miss]);
    $bitmap = fpmRequest($url, $query);
    fpmAssertQuery($bitmap, 1, 1, 'MaybePresent');
    // Restore the benchmark's original bitmap before measuring latency.
    fpmRequest($url, $base + ['action' => 'rebuild']);
    fpmRequest($url, $base + ['action' => 'clear']);

    $workloads = [];
    foreach ([1, 1000] as $requests) {
        $present = $requests === 1 ? 0 : 100;
        $samples = [];
        for ($pass = -1; $pass < 5; $pass++) {
            $paths = ['direct', 'bypass', 'uncached', 'cache_cold', 'cache_warm'];
            if ($pass % 2 !== 0) {
                $paths = array_reverse($paths);
            }
            foreach ($paths as $path) {
                $parameters = $base + ['action' => 'query', 'requests' => $requests, 'present' => $present,
                    'mode' => $path === 'direct' ? 'direct' : ($path === 'bypass' ? 'bypass' : 'gate'),
                    'cache' => $path === 'uncached' ? 0 : 1];
                if ($requests === 1) {
                    $parameters['value'] = $miss;
                }
                if ($path === 'cache_cold') {
                    fpmRequest($url, $base + ['action' => 'clear']);
                }
                if ($path === 'cache_warm') {
                    fpmRequest($url, $query);
                }
                $report = fpmRequest($url, $parameters);
                $executor = $report['executor'] ?? null;
                fpmDriverCheck(is_array($executor), 'FPM query profile is missing.');
                $expectedCalls = match ($path) {
                    'uncached' => 3 * $requests,
                    'cache_cold' => $requests + 2,
                    'cache_warm' => $requests,
                    default => 0,
                };
                fpmDriverCheck(($executor['calls'] ?? null) === $expectedCalls,
                    'Measured FPM Redis count does not match cold/warm request scope: '.$path);
                if ($path === 'direct' || $path === 'bypass') {
                    fpmDriverCheck(($report['authoritative_queries'] ?? null) === $requests,
                        'SQL-only path did not execute one authoritative query per request.');
                }
                if ($pass >= 0) {
                    $queryMs = $report['query_elapsed_ms'] ?? null;
                    $httpMs = $report['http_elapsed_ms'] ?? null;
                    fpmDriverCheck(is_float($queryMs) && is_float($httpMs), 'FPM timings are missing.');
                    $samples[$path]['query_ms'][] = $queryMs;
                    $samples[$path]['http_ms'][] = $httpMs;
                    $samples[$path]['sql_queries'][] = $report['authoritative_queries'];
                    $samples[$path]['executor'][] = $executor;
                    $samples[$path]['pid'][] = $report['pid'];
                }
            }
        }
        foreach ($samples as &$sample) {
            $sample['median_query_ms'] = fpmMedian($sample['query_ms']);
            $sample['median_http_ms'] = fpmMedian($sample['http_ms']);
        }
        unset($sample);
        $workloads[] = ['requests_per_http_request' => $requests, 'present_requests' => $present, 'paths' => $samples];
    }

    return ['schema_version' => 1, 'status' => 'passed', 'database' => $engine,
        'database_server_version' => $setup['database_server_version'], 'php' => $setup['php'],
        'package_reference' => $setup['package_reference'], 'sapi' => 'fpm-fcgi', 'apcu_enabled' => true,
        'cross_request' => ['cold' => $cold, 'warm' => $warm],
        'guards' => ['changed_control_bounded_retry' => $retried, 'after_retry_warm' => $retryWarm,
            'metadata_mismatch_sql_fallback' => $metadata, 'after_metadata_cache_evicted' => $afterMetadata,
            'health_change_sql_fallback' => $health, 'after_health_cache_evicted' => $afterHealth,
            'live_bitmap_preadd_rollback' => $bitmap],
        'workloads' => $workloads, 'warmup_repetitions' => 1, 'measured_repetitions' => 5,
        'limits' => ['Dedicated disposable FPM/APCu/Redis/MySQL/PostgreSQL task services; no concurrency or production-load claim.',
            'Query timing excludes Laravel bootstrap/PDO connection; HTTP timing includes request startup and response transfer.',
            'No timing threshold assertion; correctness and actual Redis/SQL counts are asserted.',
            'APCu persists across independent HTTP requests; cache clear is restricted to this isolated fixture pool.']];
}

try {
    $url = $argv[1] ?? null;
    $engine = $argv[2] ?? null;
    fpmDriverCheck(is_string($url) && is_string($engine), 'Usage: php fpm-benchmark-driver.php <http-url> <mysql|pgsql>');
    echo json_encode(runFpmBenchmark($url, $engine), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
