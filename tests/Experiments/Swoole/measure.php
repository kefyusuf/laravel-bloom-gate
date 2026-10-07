<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\MeasurementReport;

require_once __DIR__.'/MeasurementReport.php';

$file = $argv[1] ?? throw new InvalidArgumentException('A report JSON path is required.');
$body = file_get_contents($file);
if ($body === false) {
    throw new RuntimeException('Report is unavailable.');
}
try {
    $report = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $result = MeasurementReport::evaluate($report);
} catch (JsonException) {
    $result = ['verdict' => 'INCONCLUSIVE', 'reasons' => ['Malformed report JSON.']];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
