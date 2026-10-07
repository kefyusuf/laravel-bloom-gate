<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

if (getenv('GENERATOR_NATIVE_TESTS') !== '1') {
    it('requires the native controlled receiver')->skip();

    return;
}

/** @return array<string, mixed> */
function generatorHttp(string $path): array
{
    $body = file_get_contents('http://127.0.0.1:8001/'.$path);
    if ($body === false) {
        throw new RuntimeException('Controlled receiver did not respond.');
    }
    $reply = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($reply)) {
        throw new RuntimeException('Invalid controlled receiver response.');
    }

    /** @var array<string, mixed> $reply */
    return $reply;
}

/** @param array<string, mixed> $reply */
function generatorCount(array $reply, string $name): int
{
    $value = $reply[$name] ?? null;
    if (! is_int($value)) {
        throw new RuntimeException('Invalid receiver counter.');
    }

    return $value;
}

it('counts controlled synthetic responses independently without SQL or Redis', function (): void {
    $server = new Process(['php', __DIR__.'/generator-server.php'], __DIR__, ['GENERATOR_PORT' => '8001'], timeout: null);
    $server->start();
    try {
        $deadline = microtime(true) + 10;
        do {
            if (! $server->isRunning()) {
                throw new RuntimeException('Receiver exited: '.$server->getOutput().$server->getErrorOutput());
            }
            set_error_handler(static fn (): bool => true);
            try {
                $socket = stream_socket_client('tcp://127.0.0.1:8001', $code, $message, 0.1);
            } finally {
                restore_error_handler();
            }
            if (is_resource($socket)) {
                fclose($socket);
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        $before = generatorHttp('generator-info');
        $pids = [];
        foreach (range(0, 7) as $index) {
            $present = $index % 2 === 0;
            $key = $present ? 'member-0000000' : 'absent-0';
            $start = hrtime(true);
            $reply = generatorHttp('measure/generator?key='.$key.'&delay=25');
            expect($reply['exists'])->toBe($present);
            expect($reply['membership'])->toBe('Bypassed');
            expect($reply['sql_calls'])->toBe(0);
            expect($reply['redis_calls'])->toBe(0);
            expect($reply['controlled_delay_ms'])->toBe(25);
            expect($reply['query_ms'])->toBeGreaterThanOrEqual(25);
            expect((hrtime(true) - $start) / 1e6)->toBeGreaterThanOrEqual(25);
            $pids[] = generatorCount($reply, 'pid');
        }
        expect(array_unique($pids))->toHaveCount(4);
        $after = generatorHttp('generator-info');
        expect(generatorCount($after, 'started') - generatorCount($before, 'started'))->toBe(8);
        expect(generatorCount($after, 'completed') - generatorCount($before, 'completed'))->toBe(8);
        expect($after['failed'])->toBe(0);
        expect($after['workers'])->toBe(4);
        expect($after['timer_failures'])->toBe(0);
    } finally {
        $server->stop();
    }
})->group('generator');
