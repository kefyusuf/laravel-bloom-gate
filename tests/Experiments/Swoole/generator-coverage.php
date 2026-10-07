<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\GeneratorObservation;

require_once __DIR__.'/GeneratorObservation.php';

try {
    $timeline = [];
    $body = file_get_contents($argv[1] ?? '');
    if ($body === false) {
        throw new RuntimeException('Timeline is missing.');
    }
    foreach (explode("\n", trim($body)) as $line) {
        $timeline[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
    $times = [];
    $body = file_get_contents($argv[2] ?? '');
    if ($body === false) {
        throw new RuntimeException('Generator cgroup observations are missing.');
    }
    foreach (explode("\n", trim($body)) as $line) {
        if (! preg_match('/^([0-9]+) usage_usec [0-9]+,.*nr_throttled [0-9]+,.*throttled_usec [0-9]+,.* memory_current [0-9]+$/', $line, $match)) {
            throw new RuntimeException('Malformed generator cgroup observation.');
        }
        $times[] = (int) $match[1] * 1000;
    }
    $body = file_get_contents($argv[3] ?? '');
    if ($body === false) {
        throw new RuntimeException('Measurement cell is missing.');
    }
    $cell = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($cell)) {
        throw new RuntimeException('Malformed measurement cell.');
    }
    $result = GeneratorObservation::evaluate($timeline, $times, $cell['measurement_start_epoch_ms'] ?? null, $cell['measurement_end_epoch_ms'] ?? null);
} catch (Throwable $failure) {
    $result = ['verdict' => 'INCONCLUSIVE', 'reasons' => [$failure->getMessage()]];
}
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
