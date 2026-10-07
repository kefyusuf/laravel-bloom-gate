<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\GeneratorReport;
use Symfony\Component\Process\Process;

if (is_file(__DIR__.'/GeneratorReport.php')) {
    require_once __DIR__.'/GeneratorReport.php';
}

/** @return array{window_audit: array<string, mixed>, ...} */
function validGeneratorCell(): array
{
    return ['path' => 'generator', 'workers' => 4, 'vus' => 1024, 'offered_rate' => 4800, 'window_basis' => 'scenario-start-time',
        'measurement_contract' => 'scheduled-completeness-actual-window-v1',
        'execution_topology' => 'single-local-constant-arrival', 'builtin_iterations' => 432000,
        'builtin_http_requests' => 432000,
        'window_audit' => ['index_interpretation' => 'zero-drop-local-only',
            'scheduled_started' => 288000, 'scheduled_completed' => 288000,
            'warmup_entered' => 0, 'extra_entered' => 0, 'scheduled_outside' => 0,
            'scheduled_early' => 0, 'scheduled_late' => 0],
        'controlled_delay_ms' => 150, 'warmup_seconds' => 30, 'duration_seconds' => 60,
        'responses' => 288000, 'started_iterations' => 288000, 'completed_iterations' => 288000,
        'unfinished_iterations' => 0, 'dropped_iterations' => 0, 'errors' => 0,
        'parity_failures' => 0, 'false_negatives' => 0, 'unknown_membership' => 0,
        'total_started_iterations' => 432000, 'total_completed_iterations' => 432000,
        'total_errors' => 0, 'total_parity_failures' => 0, 'total_false_negatives' => 0,
        'total_unknown_membership' => 0, 'total_delay_mismatches' => 0,
        'sql_calls' => 0, 'redis_calls' => 0, 'elapsed_seconds' => 60,
        'p50_ms' => 151, 'p99_ms' => 152, 'generator_saturated' => false,
        'receiver_delta' => ['started' => 432000, 'completed' => 432000, 'failed' => 0],
        'worker_pids' => [100, 101, 102, 103], 'worker_requests' => [108000, 108000, 108000, 108000],
        'resources' => ['samples' => 10, 'generator_cpu_max' => 100, 'receiver_cpu_max' => 50,
            'generator_memory_percent_max' => 30, 'receiver_memory_percent_max' => 20,
            'generator_oom' => false, 'receiver_oom' => false, 'generator_exit' => 0,
            'receiver_restarts' => 0, 'receiver_running' => true]];
}

it('admits only independently counted generation within the fixed envelope', function (): void {
    expect(GeneratorReport::evaluateCell(validGeneratorCell())['verdict'])->toBe('PASS');
});

it('rejects scheduled work lost before the receiver can count it', function (): void {
    $cell = validGeneratorCell();
    $cell['dropped_iterations'] = 1;
    expect(GeneratorReport::evaluateCell($cell)['verdict'])->toBe('INCONCLUSIVE');
});

it('rejects unobserved resource safety even when every request arrived', function (): void {
    $cell = validGeneratorCell();
    unset($cell['resources']);
    expect(GeneratorReport::evaluateCell($cell)['verdict'])->toBe('INCONCLUSIVE');
});

it('rejects impossible percentile ordering', function (): void {
    $cell = validGeneratorCell();
    $cell['p50_ms'] = 250;
    $cell['p99_ms'] = 151;
    expect(GeneratorReport::evaluateCell($cell)['verdict'])->toBe('INCONCLUSIVE');
});

it('rejects a measurement window selected by successful iteration number', function (): void {
    $cell = validGeneratorCell();
    $cell['window_basis'] = 'successful-iteration-index';
    expect(GeneratorReport::evaluateCell($cell)['verdict'])->toBe('INCONCLUSIVE');
});

it('admits explained clock-window crossings while retaining nominal completeness', function (): void {
    $cell = validGeneratorCell();
    $cell['measurement_contract'] = 'scheduled-completeness-actual-window-v1';
    $cell['builtin_iterations'] = 432000;
    $cell['builtin_http_requests'] = 432000;
    $cell['execution_topology'] = 'single-local-constant-arrival';
    $cell['window_audit'] = ['index_interpretation' => 'zero-drop-local-only',
        'scheduled_started' => 288000, 'scheduled_completed' => 288000,
        'warmup_entered' => 6, 'extra_entered' => 0, 'scheduled_outside' => 3,
        'scheduled_early' => 0, 'scheduled_late' => 3];
    $cell['responses'] = $cell['started_iterations'] = $cell['completed_iterations'] = 288003;
    expect(GeneratorReport::evaluateCell($cell)['verdict'])->toBe('PASS');
});

it('requires complete audit and builtin evidence without accepting nominal loss', function (string $case): void {
    $cell = validGeneratorCell();
    match ($case) {
        'legacy' => $cell['measurement_contract'] = 'legacy',
        'audit' => $cell['window_audit'] = null,
        'builtin' => $cell['builtin_iterations'] = null,
        'lost nominal' => $cell['window_audit']['scheduled_started'] = 287998,
        'pending nominal' => $cell['window_audit']['scheduled_completed'] = 287999,
        'unexplained actual' => $cell['responses'] = $cell['started_iterations'] = $cell['completed_iterations'] = 288003,
        'impossible extra' => $cell['window_audit']['extra_entered'] = 1,
        'impossible split' => $cell['window_audit']['scheduled_late'] = 1,
        'builtin mismatch' => $cell['builtin_http_requests'] = 431999,
        'topology' => $cell['execution_topology'] = 'distributed',
        'unavailable index' => $cell['window_audit']['index_interpretation'] = 'unavailable',
        default => throw new InvalidArgumentException('Unknown contract test case.'),
    };
    expect(GeneratorReport::evaluateCell($cell)['verdict'])->toBe('INCONCLUSIVE');
})->with(['legacy', 'audit', 'builtin', 'lost nominal', 'pending nominal', 'unexplained actual',
    'impossible extra', 'impossible split', 'builtin mismatch', 'topology', 'unavailable index']);

/** @return array{identity: array<string, mixed>, profiles: list<array{cell: array{identity: array<string, mixed>, ...}, raw: string}>, negative_control: array{cell: array{receiver_delta: array<string, mixed>, ...}, raw: string}} */
function validGeneratorQualification(): array
{
    $identity = ['source_ref' => str_repeat('a', 40), 'receiver_image' => 'sha256:'.str_repeat('b', 64),
        'k6_image_id' => 'sha256:'.str_repeat('c', 64), 'generator_cpus' => 4,
        'generator_memory_bytes' => 4294967296, 'receiver_cpus' => 2,
        'receiver_memory_bytes' => 268435456, 'fixed_vus' => 1024, 'workers' => 4,
        'sources' => ['load.js' => str_repeat('d', 64), 'Dockerfile' => str_repeat('e', 64)]];
    $profiles = [];
    foreach ([1, 2, 3] as $block) {
        foreach ([0, 25, 100, 150] as $delay) {
            $cell = validGeneratorCell();
            $cell['block'] = $block;
            $cell['controlled_delay_ms'] = $delay;
            $cell['p50_ms'] = $delay + 1;
            $cell['p99_ms'] = $delay + 2;
            $cell['identity'] = $identity;
            $profiles[] = ['cell' => $cell, 'raw' => "qualification-block-$block-delay-$delay.json"];
        }
    }

    $negative = validGeneratorCell();
    $negative['identity'] = $identity;
    $negative['offered_rate'] = 1200;
    $negative['vus'] = 128;
    $negative['controlled_delay_ms'] = 150;
    $negative['warmup_seconds'] = 2;
    $negative['duration_seconds'] = 5;
    $negative['dropped_iterations'] = 1;
    $negative['total_started_iterations'] = $negative['total_completed_iterations'] = 8399;
    $negative['builtin_iterations'] = $negative['builtin_http_requests'] = 8399;
    $negative['receiver_delta'] = ['started' => 8399, 'completed' => 8399, 'failed' => 0];

    return ['identity' => $identity, 'profiles' => $profiles,
        'negative_control' => ['cell' => $negative, 'raw' => 'negative-control.json']];
}

it('admits qualification only after all four delays pass in three distinct blocks', function (): void {
    expect(GeneratorReport::evaluateQualification(validGeneratorQualification())['verdict'])->toBe('PASS');
});

it('rejects qualification without its deliberately deficient negative control', function (): void {
    $run = validGeneratorQualification();
    unset($run['negative_control']);
    expect(GeneratorReport::evaluateQualification($run)['verdict'])->toBe('INCONCLUSIVE');
});

it('rejects partial duplicated mixed-source or failed qualification evidence', function (string $case): void {
    $run = validGeneratorQualification();
    match ($case) {
        'partial' => array_pop($run['profiles']),
        'duplicate cell' => $run['profiles'][11] = $run['profiles'][0],
        'duplicate artifact' => $run['profiles'][11]['raw'] = $run['profiles'][0]['raw'],
        'negative reused' => $run['profiles'][0]['raw'] = 'negative-control.json',
        'source' => $run['profiles'][0]['cell']['identity']['source_ref'] = str_repeat('f', 40),
        'budget' => $run['identity']['generator_cpus'] = 8,
        'failed cell' => $run['profiles'][5]['cell']['dropped_iterations'] = 1,
        'invalid block' => $run['profiles'][0]['cell']['block'] = 4,
        'invalid delay' => $run['profiles'][0]['cell']['controlled_delay_ms'] = 50,
        'control passed' => $run['negative_control']['cell']['dropped_iterations'] = 0,
        default => throw new InvalidArgumentException('Unknown qualification test case.'),
    };
    expect(GeneratorReport::evaluateQualification($run)['verdict'])->toBe('INCONCLUSIVE');
})->with(['partial', 'duplicate cell', 'duplicate artifact', 'negative reused', 'source', 'budget',
    'failed cell', 'invalid block', 'invalid delay', 'control passed']);

it('rejects a negative control contaminated by errors or missing completion evidence', function (string $case): void {
    $run = validGeneratorQualification();
    match ($case) {
        'errors' => $run['negative_control']['cell']['total_errors'] = 1,
        'early responses' => $run['negative_control']['cell']['total_delay_mismatches'] = 1,
        'missing builtin' => $run['negative_control']['cell']['builtin_iterations'] = null,
        'receiver failed' => $run['negative_control']['cell']['receiver_delta']['failed'] = 1,
        default => throw new InvalidArgumentException('Unknown negative-control test case.'),
    };
    expect(GeneratorReport::evaluateQualification($run)['verdict'])->toBe('INCONCLUSIVE');
})->with(['errors', 'early responses', 'missing builtin', 'receiver failed']);

it('applies qualification completeness through the collector CLI', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'generator-contract-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate contract input.');
    }
    try {
        $run = validGeneratorQualification();
        file_put_contents($path, json_encode($run, JSON_THROW_ON_ERROR));
        $process = new Process(['php', __DIR__.'/generator-evaluate.php', $path, '--qualification']);
        $process->mustRun();
        expect(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toMatchArray(['verdict' => 'PASS', 'reasons' => []]);
        array_pop($run['profiles']);
        file_put_contents($path, json_encode($run, JSON_THROW_ON_ERROR));
        $process->mustRun();
        expect(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toMatchArray(['verdict' => 'INCONCLUSIVE']);
    } finally {
        unlink($path);
    }
});

it('checks negative-control safety through the CLI before positive cells exist', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'generator-preflight-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate preflight input.');
    }
    try {
        $run = validGeneratorQualification();
        unset($run['profiles']);
        file_put_contents($path, json_encode($run, JSON_THROW_ON_ERROR));
        $process = new Process(['php', __DIR__.'/generator-evaluate.php', $path, '--negative-control']);
        $process->mustRun();
        expect(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toMatchArray([
            'verdict' => 'VALID_NEGATIVE_CONTROL', 'reasons' => []]);
        $run['negative_control']['cell']['total_errors'] = 1;
        file_put_contents($path, json_encode($run, JSON_THROW_ON_ERROR));
        $process->mustRun();
        expect(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toMatchArray(['verdict' => 'INCONCLUSIVE']);
    } finally {
        unlink($path);
    }
});
