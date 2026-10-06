<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

if (getenv('SHARED_MEMORY_NATIVE_TESTS') !== '1') {
    it('requires the native HTTP query runtime')->skip();

    return;
}

function queryServerStart(bool $publish): Process
{
    $process = new Process(['php', 'artisan', 'octane:start', '--server=swoole',
        '--host=127.0.0.1', '--port=8000', '--workers=4', '--task-workers=0', '--max-requests=0'],
        '/experiment', ['SHARED_MEMORY_PUBLISH' => $publish ? '1' : '0'], timeout: null);
    $process->start();
    $deadline = microtime(true) + 120;
    do {
        if (! $process->isRunning()) {
            throw new RuntimeException('Query server exited: '.$process->getOutput().$process->getErrorOutput());
        }
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('tcp://127.0.0.1:8000', $code, $message, 0.1);
        } finally {
            restore_error_handler();
        }
        if (is_resource($socket)) {
            fclose($socket);

            return $process;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    $process->stop();
    throw new RuntimeException('Query server startup timed out.');
}

function queryServerStop(): void
{
    (new Process(['php', 'artisan', 'octane:stop'], '/experiment'))->mustRun();
    $process = $GLOBALS['query_process'] ?? null;
    if ($process instanceof Process) {
        $process->stop(3);
    }
}

/** @return array<string, bool|int|string> */
function queryHttp(string $key = 'absent', string $route = 'query'): array
{
    $body = file_get_contents('http://127.0.0.1:8000/'.$route.'?key='.rawurlencode($key), false,
        stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => 'Connection: close']]));
    expect($http_response_header[0] ?? '')->toContain('200 OK');
    if ($body === false) {
        throw new RuntimeException('Query HTTP request failed.');
    }
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($payload)) {
        throw new RuntimeException('Query HTTP response is not an object.');
    }
    $result = [];
    foreach ($payload as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('Invalid HTTP payload key.');
        }
        if (! is_bool($value) && ! is_int($value) && ! is_string($value)) {
            throw new RuntimeException('Invalid HTTP payload value.');
        }
        $result[$key] = $value;
    }

    return $result;
}

beforeAll(function (): void {
    $GLOBALS['query_process'] = queryServerStart(true);
});
afterAll(function (): void {
    queryServerStop();
});

it('all_workers_use_real_query_gate_and_exact_sql_fallback', function (): void {
    $pids = [];
    $incarnations = [];
    for ($i = 0; $i < 24; $i++) {
        $present = $i % 2 === 0;
        $key = $present ? 'member-'.str_pad((string) ($i * 41111), 7, '0', STR_PAD_LEFT) : 'absent';
        $reply = queryHttp($key);
        expect($reply['exists'])->toBe($present);
        expect($reply['membership'])->toBe($present ? 'MaybePresent' : 'DefinitelyAbsent');
        expect($reply['sql_calls'])->toBe($present ? 1 : 0);
        $pids[] = $reply['pid'];
        $incarnations[] = $reply['filter'];
    }
    expect(array_unique($pids))->toHaveCount(4);
    expect(array_unique($incarnations))->toHaveCount(1);
})->group('swoole');

it('worker_restart_retains_authorization_and_parent_restart_requires_sql', function (): void {
    $before = queryHttp();
    $old = [];
    for ($i = 0; $i < 24; $i++) {
        $old[] = queryHttp()['pid'];
    }
    expect(array_unique($old))->toHaveCount(4);
    queryHttp(route: 'reload');
    $deadline = microtime(true) + 10;
    do {
        usleep(100000);
        $after = queryHttp();
        if (! in_array($after['pid'], $old, true)) {
            break;
        }
    } while (microtime(true) < $deadline);
    expect(in_array($after['pid'], $old, true))->toBeFalse();
    expect($after['filter'])->toBe($before['filter']);
    expect($after['membership'])->toBe('DefinitelyAbsent');
    expect($after['sql_calls'])->toBe(0);
    queryServerStop();
    $GLOBALS['query_process'] = queryServerStart(false);
    $fresh = queryHttp();
    expect($fresh['filter'])->not->toBe($before['filter']);
    expect($fresh['membership'])->toBe('Bypassed');
    expect($fresh['sql_calls'])->toBe(1);
    expect($fresh['exists'])->toBeFalse();
    $present = queryHttp('member-0999999');
    expect($present['membership'])->toBe('Bypassed');
    expect($present['sql_calls'])->toBe(1);
    expect($present['exists'])->toBeTrue();
})->group('swoole');
