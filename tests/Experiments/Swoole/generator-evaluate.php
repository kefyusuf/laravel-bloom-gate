<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\GeneratorReport;

require_once __DIR__.'/GeneratorReport.php';
$file = $argv[1] ?? throw new InvalidArgumentException('A cell JSON path is required.');
$body = file_get_contents($file);
if ($body === false) {
    throw new RuntimeException('Cell is unavailable.');
}
try {
    $input = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $result = ($argv[2] ?? null) === '--qualification'
        ? GeneratorReport::evaluateQualification($input)
        : GeneratorReport::evaluateCell($input);
} catch (JsonException) {
    $result = ['verdict' => 'INCONCLUSIVE', 'reasons' => ['Malformed JSON.']];
}
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
