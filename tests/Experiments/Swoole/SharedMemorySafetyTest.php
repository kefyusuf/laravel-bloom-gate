<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

if (getenv('SHARED_MEMORY_NATIVE_TESTS') !== '1') {
    it('requires its isolated native runtime')->skip();

    return;
}

/** @return array<string, mixed> */
function capabilityRequest(string $action = 'probe'): array
{
    $context = stream_context_create(['http' => ['timeout' => 5, 'header' => 'Connection: close']]);
    $body = file_get_contents('http://127.0.0.1:8000/'.$action, false, $context);
    if ($body === false) {
        throw new RuntimeException('Capability HTTP request failed.');
    }
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($payload)) {
        throw new RuntimeException('Capability HTTP payload is not an object.');
    }

    $validated = [];
    foreach ($payload as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('Capability payload key is invalid.');
        }
        $validated[$key] = $value;
    }

    return $validated;
}

/** @return array{worker_id: int, pid: int, parent_pid: int, incarnation: string, sentinel: string, marker_pid: int, published: bool} */
function capabilityProbe(): array
{
    $reply = capabilityRequest();
    foreach (['worker_id', 'pid', 'parent_pid', 'marker_pid'] as $key) {
        if (! is_int($reply[$key] ?? null)) {
            throw new RuntimeException('Probe integer field is invalid: '.$key);
        }
    }
    foreach (['incarnation', 'sentinel'] as $key) {
        if (! is_string($reply[$key] ?? null)) {
            throw new RuntimeException('Probe string field is invalid: '.$key);
        }
    }
    if (! is_bool($reply['published'] ?? null)) {
        throw new RuntimeException('Probe publication state is invalid.');
    }
    /** @var array{worker_id: int, pid: int, parent_pid: int, incarnation: string, sentinel: string, marker_pid: int, published: bool} $reply */

    return $reply;
}

function capabilityStart(): Process
{
    (new Process(['php', 'artisan', 'octane:stop'], '/experiment'))->run();
    $process = new Process(['php', 'artisan', 'octane:start', '--server=swoole',
        '--host=127.0.0.1', '--port=8000', '--workers=4', '--task-workers=0',
        '--max-requests=0'], '/experiment', timeout: null);
    $process->start();
    $deadline = microtime(true) + 15;
    do {
        if (! $process->isRunning()) {
            throw new RuntimeException('Octane exited before readiness: '.$process->getOutput().$process->getErrorOutput());
        }
        if (capabilityListening()) {
            return $process;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    $process->stop();

    throw new RuntimeException('Octane startup timed out.');
}

beforeAll(function (): void {
    $GLOBALS['capability_process'] = capabilityStart();
});

function capabilityListening(): bool
{
    set_error_handler(static fn (): bool => true);
    try {
        $socket = stream_socket_client('tcp://127.0.0.1:8000', $code, $message, 0.1);
    } finally {
        restore_error_handler();
    }
    if (! is_resource($socket)) {
        return false;
    }
    fclose($socket);

    return true;
}

function capabilityStop(): void
{
    (new Process(['php', 'artisan', 'octane:stop'], '/experiment'))->mustRun();
    $process = $GLOBALS['capability_process'] ?? null;
    if ($process instanceof Process) {
        $process->stop(3);
    }
    $deadline = microtime(true) + 10;
    do {
        if (! capabilityListening()) {
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Octane did not release its listening socket.');
}

afterAll(function (): void {
    capabilityStop();
});

it('workers_share_parent_table', function (): void {
    $marker = capabilityRequest('touch')['pid'];
    $replies = [];
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $reply = capabilityProbe();
        $replies[$reply['worker_id']] = $reply;
        if (count($replies) === 4) {
            break;
        }
    }
    expect(count($replies))->toBe(4);
    expect(array_unique(array_column($replies, 'incarnation')))->toHaveCount(1);
    expect(array_unique(array_column($replies, 'pid')))->toHaveCount(4);
    foreach ($replies as $reply) {
        expect($reply['incarnation'])->toMatch('/^[a-f0-9]{32}$/');
        expect($reply['parent_pid'])->toBeGreaterThan(0);
        expect($reply['parent_pid'])->not->toBe($reply['pid']);
        expect($reply['sentinel'])->toBe('parent-created');
        expect($reply['marker_pid'])->toBe($marker);
        expect($reply['published'])->toBeFalse();
    }
})->group('swoole');

it('worker_restart_preserves_parent_table', function (): void {
    $before = capabilityProbe();
    $oldPids = [];
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $oldPids[] = capabilityProbe()['pid'];
    }
    expect(array_unique($oldPids))->toHaveCount(4);
    capabilityRequest('reload');
    $deadline = microtime(true) + 10;
    do {
        usleep(100000);
        $after = capabilityProbe();
        if (! in_array($after['pid'], $oldPids, true)) {
            break;
        }
    } while (microtime(true) < $deadline);
    expect(in_array($after['pid'], $oldPids, true))->toBeFalse();
    expect($after['incarnation'])->toBe($before['incarnation']);
    expect($after['sentinel'])->toBe('parent-created');
})->group('swoole');

it('parent_restart_changes_incarnation', function (): void {
    $before = capabilityProbe();
    capabilityStop();
    $GLOBALS['capability_process'] = capabilityStart();
    $after = capabilityProbe();
    expect($after['incarnation'])->not->toBe($before['incarnation']);
    expect($after['published'])->toBeFalse();
})->group('swoole');

it('consumer_cannot_mutate_sealed_dataset', function (): void {
    $reply = capabilityRequest('database');
    expect($reply['rows'])->toBe(1000000);
    expect($reply['first'])->toBe('member-0000000');
    expect($reply['last'])->toBe('member-0999999');
    expect($reply['denied'])->toBe(['insert', 'update', 'delete']);
    expect($reply['username'])->toStartWith('reader@');
    expect($reply['read_only'])->toBe(1);
    expect($reply['writer_credentials_present'])->toBeFalse();
    if (! is_array($reply['grants'] ?? null)) {
        throw new RuntimeException('SQL grants are invalid.');
    }
    expect($reply['grants'])->toHaveCount(2);
    expect($reply['grants'][0])->toContain('GRANT USAGE ON *.*');
    expect($reply['grants'][1])->toContain('GRANT SELECT ON `demo`.`members`');
})->group('swoole');
