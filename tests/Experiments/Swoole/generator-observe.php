<?php

declare(strict_types=1);

/** Bounded independent CLI observer; cumulative snapshots, not interval quantiles. */
/** @param array<string,array{request_start_epoch_ms:int,request_end_epoch_ms?:int,elapsed_ms?:float,failure_kind?:string}> $requests */
function generatorObserveJson(string $url, string $stage, array &$requests): mixed
{
    $started = hrtime(true);
    $requests[$stage] = ['request_start_epoch_ms' => (int) floor(microtime(true) * 1000)];
    try {
        $context = stream_context_create(['http' => ['timeout' => 0.5]]);
        $body = @file_get_contents($url, false, $context, 0, 524289);
        if ($body === false) {
            $requests[$stage]['failure_kind'] = 'transport_or_read_unavailable';
            throw new RuntimeException('Observation transport or read is unavailable.');
        }
        if (strlen($body) > 524288) {
            $requests[$stage]['failure_kind'] = 'oversized_body';
            throw new RuntimeException('Observation endpoint exceeds its bound.');
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $requests[$stage]['failure_kind'] = 'invalid_json';
            throw new RuntimeException('Observation endpoint returned invalid JSON.');
        }
        if (! is_array($decoded) || ($stage === 'k6_metrics' && ! is_array($decoded['data'] ?? null))) {
            $requests[$stage]['failure_kind'] = 'invalid_schema';
            throw new RuntimeException('Observation endpoint returned an invalid schema.');
        }

        return $decoded;
    } finally {
        $requests[$stage]['request_end_epoch_ms'] = (int) floor(microtime(true) * 1000);
        $requests[$stage]['elapsed_ms'] = (float) ((hrtime(true) - $started) / 1000000);
    }
}

$api = $argv[1] ?? '';
$output = $argv[2] ?? '';
$seconds = (int) ($argv[3] ?? 0);
$receiverUrl = $argv[4] ?? 'http://127.0.0.1:8000/generator-info';
$cgroupRoot = $argv[5] ?? '/sys/fs/cgroup';
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
    $stage = 'k6_metrics';
    $requests = [];
    $metrics = $receiver = $cpu = $memory = null;
    $failureKind = null;
    try {
        $metrics = generatorObserveJson($api.'/v1/metrics', $stage, $requests);
        $stage = 'receiver_info';
        $receiver = generatorObserveJson($receiverUrl, $stage, $requests);
        $stage = 'receiver_cpu_stat';
        $failureKind = 'cgroup_read_unavailable';
        $cpu = @file_get_contents($cgroupRoot.'/cpu.stat');
        if ($cpu === false) {
            throw new RuntimeException('Observation cgroup read is unavailable.');
        }
        $stage = 'receiver_memory';
        $memory = @file_get_contents($cgroupRoot.'/memory.current');
        if ($memory === false) {
            throw new RuntimeException('Observation cgroup read is unavailable.');
        }
        $row = ['status' => 'observed', 'epoch_ms' => $epoch, 'k6' => $metrics, 'receiver' => $receiver,
            'receiver_cpu_stat' => $cpu, 'receiver_memory_bytes' => trim($memory)];
        $observed = true;
    } catch (Throwable $failure) {
        $row = ['status' => 'unavailable', 'epoch_ms' => $epoch, 'k6' => $metrics, 'receiver' => $receiver,
            'receiver_cpu_stat' => is_string($cpu) ? $cpu : null,
            'receiver_memory_bytes' => is_string($memory) ? trim($memory) : null,
            'reason' => $failure->getMessage(), 'failure_stage' => $stage,
            'failure_kind' => $requests[$stage]['failure_kind'] ?? $failureKind];
    }
    $row['requests'] = $requests;
    $row['observation_end_epoch_ms'] = (int) floor(microtime(true) * 1000);
    fwrite($stream, json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
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
