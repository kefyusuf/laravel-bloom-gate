<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\MeasurementReport;

if (is_file(__DIR__.'/MeasurementReport.php')) {
    require_once __DIR__.'/MeasurementReport.php';
}

/** @return array{identity: array<string, mixed>, sql_capacity: int, cells: list<array<string, mixed>>} */
function validMeasurementReport(): array
{
    $identity = ['source_ref' => str_repeat('a', 40), 'source_digest' => str_repeat('b', 64),
        'lock_digest' => str_repeat('c', 64), 'dataset_digest' => str_repeat('d', 64),
        'runtime' => 'PHP 8.4 / OpenSwoole 26.2 / Octane 2.20',
        'images' => ['php' => 'sha256:'.str_repeat('e', 64), 'mysql' => 'sha256:'.str_repeat('f', 64),
            'redis' => 'sha256:'.str_repeat('1', 64), 'k6' => 'sha256:'.str_repeat('2', 64)],
        'sql_connection' => 'worker-local-reused', 'redis_profile' => 'standalone-primary-durable-v1',
        'redis_apcu' => true, 'redis_evalsha' => true, 'sealed' => true, 'integrity_checks' => true];
    $cells = [];
    foreach ([1, 2, 3] as $block) {
        $order = match ($block) {
            1 => ['direct', 'bypass', 'redis', 'shared'],
            2 => ['redis', 'shared', 'direct', 'bypass'],
            3 => ['shared', 'direct', 'bypass', 'redis'],
        };
        foreach (['control-before', ...$order, 'control-after'] as $path) {
            $cells[] = ['block' => $block, 'path' => $path, 'identity' => $identity,
                'workers' => 4, 'offered_rate' => 200, 'absent_percent' => 90,
                'warmup_seconds' => 30, 'duration_seconds' => 60, 'responses' => 12000,
                'elapsed_seconds' => 60, 'started_iterations' => 12000,
                'completed_iterations' => 12000, 'unfinished_iterations' => 0,
                'present_responses' => 1200, 'absent_responses' => 10800, 'rps' => 200,
                'p50_ms' => 1, 'p95_ms' => 2, 'p99_ms' => $path === 'shared' ? 2 : 3,
                'errors' => 0, 'parity_failures' => 0, 'false_negatives' => 0,
                'unknown_membership' => 0, 'dropped_iterations' => 0, 'generator_saturated' => false];
        }
    }

    return ['identity' => $identity, 'sql_capacity' => 250, 'cells' => $cells];
}

it('accepts a complete paired screen only when all three runs beat SQL and Redis', function (): void {
    expect(MeasurementReport::evaluate(validMeasurementReport())['verdict'])->toBe('GO');
});

it('rejects a generator-limited calibration even when the paired timings would pass', function (array $limit): void {
    $report = validMeasurementReport();
    $report['calibration'] = [$limit];
    $result = MeasurementReport::evaluate($report);
    expect($result['verdict'])->toBe('INCONCLUSIVE');
    expect($result['reasons'])->toContain('Generator-limited calibration cannot establish SQL capacity.');
})->with([
    [['dropped_iterations' => 1, 'generator_saturated' => false]],
    [['dropped_iterations' => 0, 'generator_saturated' => true]],
]);

it('rejects incomplete or biased measurement evidence', function (string $defect): void {
    $report = validMeasurementReport();
    switch ($defect) {
        case 'source': unset($report['identity']['source_ref']);
            break;
        case 'images': $report['identity']['images'] = [];
            break;
        case 'rate': $report['cells'][1]['offered_rate'] = 199;
            break;
        case 'workers': $report['cells'][1]['workers'] = 3;
            break;
        case 'unknown': $report['cells'][1]['unknown_membership'] = 1;
            break;
        case 'drops': $report['cells'][1]['dropped_iterations'] = 1;
            break;
        case 'integrity': $report['identity']['integrity_checks'] = false;
            break;
        case 'samples': $report['cells'][1]['responses'] = 9999;
            break;
        case 'cell': array_pop($report['cells']);
            break;
        case 'duplicate': $report['cells'][1] = $report['cells'][0];
            break;
        case 'dataset':
            $identity = $report['identity'];
            $identity['dataset_digest'] = str_repeat('9', 64);
            $report['cells'][1]['identity'] = $identity;
            break;
        case 'warmup': $report['cells'][1]['warmup_seconds'] = 29;
            break;
        case 'duration': $report['cells'][1]['duration_seconds'] = 59;
            break;
        case 'parity': $report['cells'][1]['parity_failures'] = 1;
            break;
        case 'negative': $report['cells'][1]['false_negatives'] = 1;
            break;
        case 'errors': $report['cells'][1]['errors'] = 1;
            break;
        case 'saturation': $report['cells'][1]['generator_saturated'] = true;
            break;
        case 'drift': $report['cells'][5]['p99_ms'] = 3.31;
            break;
        case 'distribution': $report['cells'][1]['present_responses'] = 10000;
            break;
        case 'capacity': $report['sql_capacity'] = 500;
            break;
        case 'nan': $report['cells'][1]['p99_ms'] = NAN;
            break;
        case 'negative-count': $report['cells'][1]['errors'] = -1;
            break;
        case 'fractional-count': $report['cells'][1]['responses'] = 12000.5;
            break;
        case 'boolean': $report['cells'][1]['generator_saturated'] = 'false';
            break;
        case 'quantiles': $report['cells'][1]['p95_ms'] = 4;
            break;
        case 'rps-count': $report['cells'][1]['rps'] = 201;
            break;
        case 'path': $report['cells'][1]['path'] = 'unknown';
            break;
        case 'redis-hints': $report['identity']['redis_apcu'] = false;
            break;
        case 'redis-script': $report['identity']['redis_evalsha'] = false;
            break;
        case 'order': [$report['cells'][7], $report['cells'][8]] = [$report['cells'][8], $report['cells'][7]];
            break;
        case 'window':
            $report['cells'][1]['duration_seconds'] = 120;
            $report['cells'][1]['rps'] = 100;
            break;
        case 'warmup-mismatch': $report['cells'][1]['warmup_seconds'] = 60;
            break;
        case 'overcount':
            $report['cells'][1]['responses'] = 12100;
            $report['cells'][1]['present_responses'] = 1210;
            $report['cells'][1]['absent_responses'] = 10890;
            $report['cells'][1]['rps'] = 12100 / 60;
            break;
        case 'declared-load':
            $report['sql_capacity'] = 500000;
            foreach ($report['cells'] as &$cell) {
                $cell['offered_rate'] = 400000;
            }
            unset($cell);
            break;
        case 'started': unset($report['cells'][1]['started_iterations']);
            break;
        case 'unfinished': $report['cells'][1]['unfinished_iterations'] = 1;
            break;
        case 'completed': $report['cells'][1]['completed_iterations'] = 11999;
            break;
        case 'unaccounted': $report['cells'][1]['started_iterations'] = 12001;
            break;
        case 'elapsed': $report['cells'][1]['elapsed_seconds'] = 59;
            break;
        case 'zero-rps':
            foreach ($report['cells'] as &$cell) {
                $cell['elapsed_seconds'] = 2400000;
                $cell['rps'] = 0;
            }
            unset($cell);
            break;
        case 'sql-underload':
            $report['cells'][1]['elapsed_seconds'] = 12000 / 189;
            $report['cells'][1]['rps'] = 189;
            break;
    }
    expect(MeasurementReport::evaluate($report)['verdict'])->toBe('INCONCLUSIVE');
})->with(['source', 'images', 'rate', 'workers', 'unknown', 'drops', 'integrity', 'samples', 'cell',
    'duplicate', 'dataset', 'warmup', 'duration', 'parity', 'negative', 'errors', 'saturation',
    'drift', 'distribution', 'capacity', 'nan', 'negative-count', 'fractional-count', 'boolean',
    'quantiles', 'rps-count', 'path', 'redis-hints', 'redis-script', 'order', 'window',
    'warmup-mismatch', 'overcount', 'declared-load', 'started', 'unfinished', 'completed',
    'unaccounted', 'elapsed', 'zero-rps', 'sql-underload']);

it('includes the exact latency and control-drift boundaries', function (): void {
    $report = validMeasurementReport();
    foreach ($report['cells'] as &$cell) {
        if ($cell['path'] === 'shared') {
            $cell['p99_ms'] = 2.4;
        }
        if ($cell['path'] === 'control-after') {
            $cell['p99_ms'] = 3.3;
        }
    }
    unset($cell);
    expect(MeasurementReport::evaluate($report)['verdict'])->toBe('GO');
});

it('rejects a malformed root without throwing', function (mixed $input): void {
    expect(MeasurementReport::evaluate($input)['verdict'])->toBe('INCONCLUSIVE');
})->with([[null], [[]], ['not-an-object'], [42]]);

it('rejects a SQL-only win as a Redis-removal benefit', function (): void {
    $report = validMeasurementReport();
    $report['cells'][3]['p99_ms'] = 2.1;
    expect(MeasurementReport::evaluate($report)['verdict'])->toBe('NO-GO');
});

it('requires throughput parity in every repetition', function (): void {
    $report = validMeasurementReport();
    $report['cells'][13]['rps'] = 189;
    $report['cells'][13]['elapsed_seconds'] = 12000 / 189;
    expect(MeasurementReport::evaluate($report)['verdict'])->toBe('NO-GO');
});

it('uses observed counts and time instead of rounded RPS at the throughput boundary', function (): void {
    $report = validMeasurementReport();
    $report['cells'][13]['rps'] = 190;
    $report['cells'][13]['elapsed_seconds'] = 12000 / 189.999;
    expect(MeasurementReport::evaluate($report)['verdict'])->toBe('NO-GO');
});

it('includes exact ninety-five percent observed throughput', function (): void {
    $report = validMeasurementReport();
    $report['cells'][13]['rps'] = 190;
    $report['cells'][13]['elapsed_seconds'] = 12000 / 190;
    expect(MeasurementReport::evaluate($report)['verdict'])->toBe('GO');
});
