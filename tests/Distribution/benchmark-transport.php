<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisQuerySafetyScripts;

require __DIR__.'/vendor/autoload.php';

/** @phpstan-assert true $condition */
function transportCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, array{script: string, keys: list<string>, arguments: list<string>}> */
function transportReadCalls(string $file): array
{
    $json = file_get_contents($file);
    transportCheck(is_string($json), 'Could not read the profiling report.');
    $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    transportCheck(is_array($report) && ($report['status'] ?? null) === 'passed'
        && ($report['profile_enabled'] ?? null) === true, 'A passed profiling report is required.');
    $raw = $report['readonly_replay_calls'] ?? null;
    transportCheck(is_array($raw) && count($raw) === 3, 'Exactly three readonly script groups are required.');
    $official = [
        'snapshot' => RedisQuerySafetyScripts::readActive(),
        'contract' => RedisGenerationContractScripts::read(),
        'probe' => RedisQuerySafetyScripts::authorizedProbe(),
    ];
    $keyCounts = ['snapshot' => 1, 'contract' => 2, 'probe' => 3];
    $calls = [];
    foreach ($official as $group => $script) {
        $call = $raw[$group] ?? null;
        transportCheck(is_array($call) && ($call['script'] ?? null) === $script,
            'Replay script does not match the official readonly script: '.$group);
        $keys = $call['keys'] ?? null;
        $arguments = $call['arguments'] ?? null;
        transportCheck(is_array($keys) && array_is_list($keys) && count($keys) === $keyCounts[$group],
            'Invalid replay keys: '.$group);
        $validKeys = [];
        foreach ($keys as $key) {
            transportCheck(is_string($key) && $key !== '', 'Replay keys must be nonempty strings.');
            $validKeys[] = $key;
        }
        transportCheck(is_array($arguments) && array_is_list($arguments), 'Invalid replay arguments: '.$group);
        $validArguments = [];
        foreach ($arguments as $argument) {
            transportCheck(is_string($argument), 'Replay arguments must be strings.');
            $validArguments[] = $argument;
        }
        transportCheck($group === 'probe' ? count($arguments) >= 10 : $arguments === [],
            'Unexpected argument count: '.$group);
        $calls[$group] = ['script' => $script, 'keys' => $validKeys, 'arguments' => $validArguments];
    }

    return $calls;
}

function transportCpuUsec(Redis $redis, string $mode): int
{
    $info = $redis->info('commandstats');
    transportCheck(is_array($info), 'Redis command statistics are unavailable.');
    $stats = $info['cmdstat_'.$mode] ?? null;
    if ($stats === null) {
        return 0;
    }
    transportCheck(is_string($stats), 'Invalid Redis command statistics.');
    if (preg_match('/usec=(\d+),/', $stats, $matches) !== 1) {
        throw new RuntimeException('Redis command CPU statistics are unavailable.');
    }

    return (int) $matches[1];
}

function transportValidateResponse(string $group, mixed $answer): void
{
    transportCheck(is_array($answer) && array_is_list($answer), 'Invalid readonly response: '.$group);
    foreach ($answer as $value) {
        transportCheck(is_string($value), 'Readonly responses must contain strings: '.$group);
    }
    $valid = match ($group) {
        'snapshot' => count($answer) === 5 && $answer[0] === RedisQuerySafetyScripts::STATUS_ACTIVE,
        'contract' => count($answer) === 8 && $answer[0] === RedisGenerationContractScripts::STATUS_OK,
        'probe' => $answer === [RedisQuerySafetyScripts::RESULT_ABSENT]
            || $answer === [RedisQuerySafetyScripts::RESULT_MAYBE],
        default => false,
    };
    transportCheck($valid, 'Live fixture state is missing, corrupt or bypassed: '.$group);
}

/** @return array<string, mixed> */
function runTransportBenchmark(string $file): array
{
    $calls = transportReadCalls($file);
    $redis = new Redis;
    $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    transportCheck($port >= 1 && $port <= 65535, 'Invalid Redis port.');
    transportCheck($redis->connect($host, $port), 'Could not connect to Redis.');
    $loaded = [];
    foreach ($calls as $group => $call) {
        // SCRIPT LOAD writes only the server script cache and is excluded from measured time.
        $sha = $redis->script('load', $call['script']);
        transportCheck(is_string($sha) && $sha !== '', 'Could not load the readonly script.');
        $loaded[$group] = $sha;
    }
    $expectedResponses = [];
    foreach ($calls as $group => $call) {
        $answer = $redis->eval($call['script'], [...$call['keys'], ...$call['arguments']], count($call['keys']));
        transportValidateResponse($group, $answer);
        $expectedResponses[$group] = $answer;
    }
    $timings = ['eval' => [], 'evalsha' => []];
    $cpu = ['eval' => [], 'evalsha' => []];
    for ($pass = -1; $pass < 5; $pass++) {
        $first = ['eval' => [], 'evalsha' => []];
        $last = ['eval' => [], 'evalsha' => []];
        foreach ($pass % 2 === 0 ? ['eval', 'evalsha'] : ['evalsha', 'eval'] as $mode) {
            $before = transportCpuUsec($redis, $mode);
            $start = hrtime(true);
            for ($index = 0; $index < 1000; $index++) {
                foreach ($calls as $group => $call) {
                    $arguments = [...$call['keys'], ...$call['arguments']];
                    $answer = $mode === 'eval'
                        ? $redis->eval($call['script'], $arguments, count($call['keys']))
                        : $redis->evalsha($loaded[$group], $arguments, count($call['keys']));
                    if ($index === 0) {
                        $first[$mode][$group] = $answer;
                    }
                    if ($index === 999) {
                        $last[$mode][$group] = $answer;
                    }
                }
            }
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $after = transportCpuUsec($redis, $mode);
            transportCheck($first[$mode] === $last[$mode], 'Replay changed during readonly measurement.');
            foreach ($last[$mode] as $group => $answer) {
                transportValidateResponse($group, $answer);
            }
            transportCheck($first[$mode] === $expectedResponses,
                'Live fixture state changed after successful replay preflight.');
            if ($pass >= 0) {
                $timings[$mode][] = $elapsed;
                $cpu[$mode][] = ($after - $before) / 1000;
            }
        }
        transportCheck($last['eval'] === $last['evalsha'], 'EVAL/EVALSHA responses differ.');
    }
    $medians = [];
    foreach ($timings as $mode => $samples) {
        sort($samples, SORT_NUMERIC);
        $medians[$mode] = $samples[2];
    }
    $ping = [];
    for ($pass = -1; $pass < 5; $pass++) {
        $start = hrtime(true);
        for ($index = 0; $index < 3000; $index++) {
            $redis->ping();
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        if ($pass >= 0) {
            $ping[] = $elapsed;
        }
    }
    sort($ping, SORT_NUMERIC);
    $redis->close();

    return ['schema_version' => 1, 'status' => 'passed', 'readonly_replays' => 1000,
        'scripts_per_replay' => count($calls), 'group_order' => array_keys($calls),
        'equal_responses' => true, 'warmup_repetitions' => 1, 'measured_repetitions' => 5,
        'wall_ms' => $timings, 'median_wall_ms' => $medians, 'server_cpu_ms' => $cpu,
        'ping_3000_wall_ms' => $ping, 'ping_3000_median_ms' => $ping[2],
        'scope' => 'Raw phpredis sequential replay of identical live readonly query scripts; no Laravel registry/hashing or SQL; one fixed probe, no concurrency.',
        'limitations' => ['Requires the original live fixture Redis state and an otherwise idle disposable server.',
            'EVALSHA is only a transport control; this result does not claim production query latency improvements.',
            'SCRIPT LOAD is outside timing; first/last and cross-mode responses are compared outside timing.'],
    ];
}

try {
    $file = $argv[1] ?? null;
    transportCheck(is_string($file) && $file !== '', 'Usage: php benchmark-transport.php <profiling-report.json>');
    echo json_encode(runTransportBenchmark($file), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
