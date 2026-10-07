<?php

declare(strict_types=1);

/** Bounded independent CLI observer; cumulative snapshots, not interval quantiles. */
function generatorObserveJson(string $url): mixed
{
    $context = stream_context_create(['http' => ['timeout' => 0.5]]);
    $body = @file_get_contents($url, false, $context, 0, 524289);
    if ($body === false || strlen($body) > 524288) {
        throw new RuntimeException('Observation endpoint is unavailable or exceeds its bound.');
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

$api = $argv[1] ?? '';
$output = $argv[2] ?? '';
$seconds = (int) ($argv[3] ?? 0);
if ($api === '' || $output === '' || $seconds < 1 || $seconds > 180) {
    exit(2);
}
$stream = fopen($output, 'x');
if ($stream === false) {
    exit(2);
}
$start = hrtime(true);
$observed = false;
while (hrtime(true) - $start < $seconds * 1000000000) {
    $iteration = hrtime(true);
    $epoch = (int) floor(microtime(true) * 1000);
    try {
        $metrics = generatorObserveJson($api.'/v1/metrics');
        $receiver = generatorObserveJson('http://127.0.0.1:8000/generator-info');
        $cpu = file_get_contents('/sys/fs/cgroup/cpu.stat');
        $memory = file_get_contents('/sys/fs/cgroup/memory.current');
        if (! is_array($metrics) || ! is_array($metrics['data'] ?? null) || ! is_array($receiver)
            || $cpu === false || $memory === false) {
            throw new RuntimeException('Observation schema or cgroup evidence is unavailable.');
        }
        $row = ['status' => 'observed', 'epoch_ms' => $epoch, 'k6' => $metrics, 'receiver' => $receiver,
            'receiver_cpu_stat' => $cpu, 'receiver_memory_bytes' => trim($memory)];
        $observed = true;
    } catch (Throwable $failure) {
        $row = ['status' => 'unavailable', 'epoch_ms' => $epoch, 'k6' => null, 'receiver' => null,
            'reason' => $failure->getMessage()];
    }
    $row['observation_end_epoch_ms'] = (int) floor(microtime(true) * 1000);
    fwrite($stream, json_encode($row, JSON_THROW_ON_ERROR)."\n");
    fflush($stream);
    if ($row['status'] === 'unavailable' && $observed) {
        break;
    }
    $remaining = 1000000000 - (hrtime(true) - $iteration);
    if ($remaining > 0) {
        usleep((int) ceil($remaining / 1000));
    }
}
fclose($stream);
exit($observed ? 0 : 1);
