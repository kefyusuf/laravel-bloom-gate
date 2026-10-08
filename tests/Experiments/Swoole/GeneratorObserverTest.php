<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\GeneratorObservation;
use Symfony\Component\Process\Process;

/** @return array{exit:int|null,rows:list<array<array-key,mixed>>} */
function runGeneratorObserverFixture(string $scenario, string $receiver = 'fixture', int $seconds = 1, ?string $cgroupPath = null): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve a synthetic loopback port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($address === false) {
        throw new RuntimeException('Synthetic loopback address unavailable.');
    }
    $output = tempnam(sys_get_temp_dir(), 'generator-observer-');
    if ($output === false) {
        throw new RuntimeException('Unable to allocate observation output.');
    }
    unlink($output);
    $state = tempnam(sys_get_temp_dir(), 'generator-observer-state-');
    if ($state === false) {
        throw new RuntimeException('Synthetic receiver state unavailable.');
    }
    $server = new Process([PHP_BINARY, '-S', $address, __DIR__.'/generator-observer-fixture.php'], null,
        ['GENERATOR_OBSERVER_FIXTURE_STATE' => $state]);
    $server->setTimeout(10);
    $ownedCgroup = null;
    try {
        if ($cgroupPath === null) {
            $ownedCgroup = tempnam(sys_get_temp_dir(), 'generator-observer-cgroup-');
            if ($ownedCgroup === false) {
                throw new RuntimeException('Synthetic cgroup path unavailable.');
            }
            unlink($ownedCgroup);
            mkdir($ownedCgroup, 0700);
            file_put_contents($ownedCgroup.'/cpu.stat', "usage_usec 7\nnr_throttled 0\n");
            file_put_contents($ownedCgroup.'/memory.current', "128\n");
            $cgroupPath = $ownedCgroup;
        }
        $server->start();
        if (! $server->waitUntil(static fn (string $type, string $data): bool => str_contains($data, 'Development Server'))) {
            throw new RuntimeException('Synthetic loopback fixture did not start.');
        }
        $url = 'http://'.$address;
        $receiverUrl = $receiver === 'fixture' ? $url.'/generator-info'
            : (str_starts_with($receiver, '/') ? $url.$receiver : $receiver);
        $command = [PHP_BINARY, __DIR__.'/generator-observe.php', $url.'/'.$scenario, $output,
            (string) $seconds, $receiverUrl, $cgroupPath];
        $observer = new Process($command);
        $observer->setTimeout(10);
        $observer->run();
        $body = file_get_contents($output);
        if ($body === false || trim($body) === '') {
            throw new RuntimeException('Observer fixture output missing.');
        }
        $rows = array_map(static function (string $line): array {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Observer row must be an object.');
            }

            return $decoded;
        },
            explode("\n", trim($body)));

        return ['exit' => $observer->getExitCode(), 'rows' => $rows];
    } finally {
        $server->stop();
        if (is_file($output)) {
            unlink($output);
        }
        unlink($state);
        if (is_string($ownedCgroup)) {
            unlink($ownedCgroup.'/cpu.stat');
            unlink($ownedCgroup.'/memory.current');
            rmdir($ownedCgroup);
        }
    }
}

it('preserves successful k6 evidence when the receiver request is unavailable', function (): void {
    $result = runGeneratorObserverFixture('ok', 'http://127.0.0.1:1');
    expect($result['exit'])->toBe(1);
    expect($result['rows'])->toHaveCount(1);
    $row = $result['rows'][0];
    expect($row)->toMatchArray(['status' => 'unavailable', 'failure_stage' => 'receiver_info',
        'failure_kind' => 'transport_or_read_unavailable', 'receiver' => null]);
    expect($row['k6'])->toBe(['data' => [['id' => 'iterations', 'sample' => ['count' => 7]]]]);
    expect($row['requests'])->toHaveKeys(['k6_metrics', 'receiver_info']);
});

it('classifies malformed endpoint JSON at its original stage without exposing its body', function (): void {
    $result = runGeneratorObserverFixture('invalid-json');
    expect($result['exit'])->toBe(1);
    $row = $result['rows'][0];
    expect($row)->toMatchArray(['status' => 'unavailable', 'failure_stage' => 'k6_metrics',
        'failure_kind' => 'invalid_json', 'k6' => null, 'receiver' => null]);
    expect($row['requests'])->toHaveKey('k6_metrics');
    expect($row['requests'])->not->toHaveKey('receiver_info');
});

it('attributes invalid schema to the endpoint that supplied it', function (string $scenario, string $receiver, string $stage): void {
    $result = runGeneratorObserverFixture($scenario, $receiver);
    expect($result['exit'])->toBe(1);
    $row = $result['rows'][0];
    expect($row)->toMatchArray(['status' => 'unavailable', 'failure_stage' => $stage, 'failure_kind' => 'invalid_schema']);
    expect($row['requests'])->toHaveKey($stage);
    if ($stage === 'k6_metrics') {
        expect($row['k6'])->toBeNull();
        expect($row['requests'])->not->toHaveKey('receiver_info');
    } else {
        expect($row['k6'])->toBe(['data' => [['id' => 'iterations', 'sample' => ['count' => 7]]]]);
        expect($row['receiver'])->toBeNull();
    }
})->with([
    ['invalid-schema', 'fixture', 'k6_metrics'],
    ['ok', '/invalid-schema-receiver/generator-info', 'receiver_info'],
]);

it('admits the exact response byte bound and rejects the next byte', function (string $scenario, string $status): void {
    $result = runGeneratorObserverFixture($scenario);
    $row = $result['rows'][0];
    expect($row['status'])->toBe($status);
    if ($status === 'observed') {
        expect($result['exit'])->toBe(0);
        expect($row['k6'])->toBe(['data' => [['id' => 'iterations', 'sample' => ['count' => 7]]]]);
    } else {
        expect($result['exit'])->toBe(1);
        expect($row)->toMatchArray(['failure_stage' => 'k6_metrics', 'failure_kind' => 'oversized_body', 'k6' => null]);
    }
})->with([['at-limit', 'observed'], ['oversized', 'unavailable']]);

it('records delayed transport failure without claiming timeout from elapsed time', function (): void {
    $result = runGeneratorObserverFixture('delayed');
    $row = $result['rows'][0];
    expect($result['exit'])->toBe(1);
    expect($row)->toMatchArray(['status' => 'unavailable', 'failure_stage' => 'k6_metrics',
        'failure_kind' => 'transport_or_read_unavailable']);
    $requests = $row['requests'];
    assert(is_array($requests));
    assert(is_array($requests['k6_metrics']));
    expect($requests['k6_metrics']['elapsed_ms'])->toBeGreaterThanOrEqual(300)->toBeLessThan(3000);
    expect(json_encode($row))->not->toContain('timeout');
});

it('stops on the first unavailable sample after success and keeps coverage inconclusive', function (): void {
    $result = runGeneratorObserverFixture('ok', '/flaky-receiver/generator-info', 5);
    expect($result['exit'])->toBe(0);
    expect($result['rows'])->toHaveCount(2);
    [$observed, $unavailable] = $result['rows'];
    expect($observed['status'])->toBe('observed');
    expect($observed['receiver_cpu_stat'])->toBeString();
    expect($observed['receiver_cpu_stat'])->not->toBeEmpty();
    expect($observed['receiver_memory_bytes'])->toBeString();
    expect($observed['receiver_memory_bytes'])->not->toBeEmpty();
    expect($observed['receiver_cpu_stat'])->toBe("usage_usec 7\nnr_throttled 0\n");
    expect($observed['receiver_memory_bytes'])->toBe('128');
    expect($unavailable)->toMatchArray(['status' => 'unavailable', 'failure_stage' => 'receiver_info', 'failure_kind' => 'invalid_json']);
    expect($unavailable['k6'])->toBe(['data' => [['id' => 'iterations', 'sample' => ['count' => 7]]]]);
    assert(is_int($observed['epoch_ms']));
    assert(is_int($unavailable['epoch_ms']));
    assert(is_int($unavailable['observation_end_epoch_ms']));
    expect($unavailable['epoch_ms'] - $observed['epoch_ms'])->toBeGreaterThanOrEqual(900);
    expect(GeneratorObservation::evaluate($result['rows'], [], $observed['epoch_ms'], $unavailable['observation_end_epoch_ms'] + 1))
        ->toBe(['verdict' => 'INCONCLUSIVE', 'reasons' => ['An observation was unavailable during measurement.']]);
});

it('retains endpoint and CPU evidence when the cgroup memory read fails', function (): void {
    $directory = tempnam(sys_get_temp_dir(), 'generator-observer-cgroup-');
    if ($directory === false) {
        throw new RuntimeException('Synthetic cgroup path unavailable.');
    }
    unlink($directory);
    mkdir($directory, 0700);
    $cpu = "usage_usec 17\nnr_throttled 0\n";
    file_put_contents($directory.'/cpu.stat', $cpu);
    try {
        $result = runGeneratorObserverFixture('ok', 'fixture', 1, $directory);
        expect($result['exit'])->toBe(1);
        $row = $result['rows'][0];
        expect($row)->toMatchArray(['status' => 'unavailable', 'failure_stage' => 'receiver_memory',
            'failure_kind' => 'cgroup_read_unavailable', 'receiver_cpu_stat' => $cpu, 'receiver_memory_bytes' => null]);
        expect($row['k6'])->toBeArray();
        expect($row['receiver'])->toBeArray();
        expect(json_encode($row))->not->toContain($directory);
    } finally {
        unlink($directory.'/cpu.stat');
        rmdir($directory);
    }
});

it('retains an unavailable observation instead of inventing zero lost work', function (): void {
    $output = tempnam(sys_get_temp_dir(), 'generator-observer-');
    if ($output === false) {
        throw new RuntimeException('Unable to allocate observation output.');
    }
    unlink($output);
    try {
        $observer = new Process(['php', __DIR__.'/generator-observe.php', 'http://127.0.0.1:1', $output, '1']);
        $observer->run();
        expect($observer->getExitCode())->toBe(1);
        $body = file_get_contents($output);
        if ($body === false || $body === '') {
            throw new RuntimeException('Unavailable observation was not retained.');
        }
        $row = json_decode(trim($body), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($row)) {
            throw new RuntimeException('Unavailable observation must be an object.');
        }
        expect($row)->toMatchArray(['status' => 'unavailable', 'k6' => null, 'receiver' => null]);
        expect($row['failure_stage'] ?? null)->toBe('k6_metrics');
        expect($row['failure_kind'] ?? null)->toBe('transport_or_read_unavailable');
        $requests = $row['requests'] ?? [];
        assert(is_array($requests));
        $request = $requests['k6_metrics'] ?? [];
        assert(is_array($request));
        expect($request['request_start_epoch_ms'] ?? null)->toBeInt();
        expect($request['request_end_epoch_ms'] ?? null)->toBeInt();
        expect($request['elapsed_ms'] ?? null)->toBeFloat()->toBeGreaterThanOrEqual(0);
        assert(is_int($row['epoch_ms']));
        assert(is_int($row['observation_end_epoch_ms']));
        expect($request['request_start_epoch_ms'])->toBeGreaterThanOrEqual($row['epoch_ms']);
        expect($request['request_end_epoch_ms'])->toBeLessThanOrEqual($row['observation_end_epoch_ms']);
        expect(json_encode($row))->not->toContain('127.0.0.1');
        expect(json_encode($row))->not->toContain('timeout');
    } finally {
        if (is_file($output)) {
            unlink($output);
        }
    }
});
