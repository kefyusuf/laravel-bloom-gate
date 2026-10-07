<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Table;
use Swoole\Timer;

// Standalone synthetic receiver: it imports no Laravel, package, SQL or Redis code.
$counts = new Table(16);
foreach (['started', 'completed', 'failed'] as $name) {
    $counts->column($name, Table::TYPE_INT);
}
$counts->column('pid', Table::TYPE_INT);
if (! $counts->create() || ! $counts->set('all', ['started' => 0, 'completed' => 0, 'failed' => 0])) {
    throw new RuntimeException('Receiver counter allocation failed.');
}
$port = (int) (getenv('GENERATOR_PORT') ?: 8000);
$server = new Server('0.0.0.0', $port);
$server->set(['worker_num' => 4, 'dispatch_mode' => 1, 'log_level' => 4]);
$settings = $server->setting;
if (! is_array($settings) || ! is_int($settings['worker_num'] ?? null)) {
    throw new RuntimeException('Receiver worker setting is unavailable.');
}
$workers = $settings['worker_num'];
foreach (range(0, $workers - 1) as $worker) {
    $counts->set('worker-'.$worker, ['started' => 0, 'pid' => 0]);
}
$server->on('workerStart', function (Server $server, int $worker) use ($counts): void {
    $counts->set('worker-'.$worker, ['pid' => getmypid()]);
});
$server->on('request', function (Request $request, Response $response) use ($counts, $workers, $server): void {
    $metadata = $request->server;
    $query = $request->get;
    if (! is_array($metadata)) {
        $metadata = [];
    }
    if (! is_array($query)) {
        $query = [];
    }
    $response->header('Content-Type', 'application/json');
    if (($metadata['request_uri'] ?? '') === '/generator-info') {
        $pids = $traffic = [];
        foreach (range(0, $workers - 1) as $worker) {
            $pids[] = $counts->get('worker-'.$worker, 'pid');
            $traffic[] = $counts->get('worker-'.$worker, 'started');
        }
        $response->end(json_encode(['started' => $counts->get('all', 'started'),
            'completed' => $counts->get('all', 'completed'), 'failed' => $counts->get('all', 'failed'),
            'workers' => $workers, 'worker_pids' => $pids, 'worker_requests' => $traffic,
            'php' => PHP_VERSION, 'swoole' => phpversion('openswoole')], JSON_THROW_ON_ERROR));

        return;
    }
    $key = $query['key'] ?? null;
    $delay = $query['delay'] ?? '0';
    if (($metadata['request_uri'] ?? '') !== '/measure/generator' || ! is_string($key)
        || ! is_string($delay) || ! in_array($delay, ['0', '25', '100', '150'], true)) {
        $response->status(400);
        $response->end('{}');

        return;
    }
    $counts->incr('all', 'started');
    $worker = $server->worker_id;
    if (! is_int($worker)) {
        throw new RuntimeException('Receiver worker ID is unavailable.');
    }
    $counts->incr('worker-'.$worker, 'started');
    $start = hrtime(true);
    $finish = function () use ($counts, $response, $key, $delay, $start): void {
        $sent = $response->end(json_encode(['exists' => str_starts_with($key, 'member-'),
            'membership' => 'Bypassed', 'sql_calls' => 0, 'redis_calls' => 0,
            'query_ms' => (hrtime(true) - $start) / 1e6, 'controlled_delay_ms' => (int) $delay,
            'pid' => getmypid()], JSON_THROW_ON_ERROR));
        $counts->incr('all', $sent ? 'completed' : 'failed');
    };
    if ($delay === '0') {
        $finish();
    } else {
        // One millisecond covers native timer tick quantization; requested delay is a lower bound.
        Timer::after((int) $delay + 1, $finish);
    }
});
$server->start();
