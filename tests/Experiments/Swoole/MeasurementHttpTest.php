<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

if (getenv('MEASUREMENT_NATIVE_TESTS') !== '1') {
    it('requires the real measurement stack')->skip();

    return;
}

/** @return array<string, mixed> */
function measurementHttp(string $path, string $key = 'absent'): array
{
    $body = file_get_contents('http://127.0.0.1:8000/'.$path.'?key='.rawurlencode($key), false,
        stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => 'Connection: close']]));
    if (! str_contains($http_response_header[0] ?? '', '200 OK')) {
        $server = $GLOBALS['measurement_server'] ?? null;
        throw new RuntimeException('Measurement HTTP failed: '.($http_response_header[0] ?? '').' '.
            ($server instanceof Process ? $server->getOutput().$server->getErrorOutput() : ''));
    }
    if ($body === false) {
        throw new RuntimeException('Measurement HTTP request failed.');
    }
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($payload)) {
        throw new RuntimeException('Measurement HTTP payload is invalid.');
    }

    /** @var array<string, mixed> $payload */
    return $payload;
}

beforeAll(function (): void {
    $server = new Process(['php', 'artisan', 'octane:start', '--server=swoole', '--host=0.0.0.0',
        '--port=8000', '--workers=4', '--task-workers=0', '--max-requests=0'], '/experiment',
        ['SHARED_MEMORY_PUBLISH' => '1', 'SHARED_MEMORY_MEASURE' => '1'], timeout: null);
    $server->start();
    $GLOBALS['measurement_server'] = $server;
    $deadline = microtime(true) + 120;
    do {
        if (! $server->isRunning()) {
            throw new RuntimeException('Measurement server exited. '.$server->getErrorOutput());
        }
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('tcp://127.0.0.1:8000', $code, $message, 0.1);
        } finally {
            restore_error_handler();
        }
        if (is_resource($socket)) {
            fclose($socket);

            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Measurement server startup timed out.');
});

afterAll(function (): void {
    (new Process(['php', 'artisan', 'octane:stop'], '/experiment'))->mustRun();
    $server = $GLOBALS['measurement_server'] ?? null;
    if ($server instanceof Process) {
        $server->stop();
    }
});

it('four real HTTP paths agree on sealed membership across all workers', function (): void {
    $info = measurementHttp('measurement-info');
    expect($info['workers'])->toBe(4);
    $manifest = $info['manifest'];
    if (! is_array($manifest)) {
        throw new RuntimeException('Missing measured manifest.');
    }
    expect($manifest['rows'])->toBe(1000000);
    expect($info['writer_credentials_present'])->toBeFalse();
    expect($info['shared_bytes'])->toBeGreaterThan(0);
    foreach (['direct', 'bypass', 'redis', 'shared'] as $path) {
        $workers = [];
        foreach (range(0, 23) as $i) {
            $present = $i % 2 === 0;
            $reply = measurementHttp('measure/'.$path, $present ? 'member-0999999' : 'absent');
            expect($reply['exists'])->toBe($present);
            expect($reply['membership'])->toBe(in_array($path, ['direct', 'bypass'], true)
                ? 'Bypassed' : ($present ? 'MaybePresent' : 'DefinitelyAbsent'));
            expect($reply['sql_calls'])->toBe($present || in_array($path, ['direct', 'bypass'], true) ? 1 : 0);
            expect($reply['redis_calls'])->toBe($path === 'redis' ? 1 : 0);
            $pid = $reply['pid'];
            if (! is_int($pid)) {
                throw new RuntimeException('Missing measured worker PID.');
            }
            $workers[] = $pid;
        }
        expect(array_unique($workers))->toHaveCount(4);
    }
})->group('measurement');
