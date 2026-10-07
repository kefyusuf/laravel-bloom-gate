<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\GeneratorObservation;

if (is_file(__DIR__.'/GeneratorObservation.php')) {
    require_once __DIR__.'/GeneratorObservation.php';
}

it('requires observations throughout the actual measurement interval', function (): void {
    $timeline = array_map(static fn (int $time): array => ['status' => 'observed', 'epoch_ms' => $time], [1000, 2000, 3000, 4000, 5000, 6000]);
    expect(GeneratorObservation::evaluate($timeline, [1000, 2000, 3000, 4000, 5000, 6000], 2000, 5000)['verdict'])->toBe('OBSERVED');
});

it('rejects startup-only and interrupted observations', function (array $times, ?int $failure): void {
    /** @var list<int> $times */
    $timeline = array_map(static fn (int $time): array => ['status' => 'observed', 'epoch_ms' => $time], $times);
    if ($failure !== null) {
        $timeline[] = ['status' => 'unavailable', 'epoch_ms' => $failure];
    }
    expect(GeneratorObservation::evaluate($timeline, [1000, 2000, 3000, 4000, 5000, 6000], 2000, 5000)['verdict'])->toBe('INCONCLUSIVE');
})->with(['paused only' => [[1000], null], 'interrupted' => [[1000, 2000, 3000, 4000, 5000, 6000], 3000]]);

it('rejects a failed poll overlapping the measurement start', function (): void {
    $timeline = array_map(static fn (int $time): array => ['status' => 'observed', 'epoch_ms' => $time], [1000, 2000, 3000, 4000, 5000, 6000]);
    $timeline[] = ['status' => 'unavailable', 'epoch_ms' => 1900, 'observation_end_epoch_ms' => 2100];
    expect(GeneratorObservation::evaluate($timeline, [1000, 2000, 3000, 4000, 5000, 6000], 2000, 5000)['verdict'])->toBe('INCONCLUSIVE');
});

it('rejects missing generator CPU observations', function (): void {
    $timeline = array_map(static fn (int $time): array => ['status' => 'observed', 'epoch_ms' => $time], [1000, 2000, 3000, 4000, 5000, 6000]);
    expect(GeneratorObservation::evaluate($timeline, [], 2000, 5000)['verdict'])->toBe('INCONCLUSIVE');
});
