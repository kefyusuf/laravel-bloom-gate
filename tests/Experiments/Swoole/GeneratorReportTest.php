<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\GeneratorReport;

if (is_file(__DIR__.'/GeneratorReport.php')) {
    require_once __DIR__.'/GeneratorReport.php';
}

/** @return array<string, mixed> */
function validGeneratorCell(): array
{
    return ['path' => 'generator', 'workers' => 4, 'vus' => 1024, 'offered_rate' => 4800, 'window_basis' => 'scenario-start-time',
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
