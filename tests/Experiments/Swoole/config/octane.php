<?php

declare(strict_types=1);

$config = require dirname(__DIR__).'/vendor/laravel/octane/config/octane.php';
if (! is_array($config) || ! is_array($config['swoole'] ?? null)) {
    throw new RuntimeException('Octane configuration is invalid.');
}
$config['swoole']['options'] = [];
$config['server'] = 'swoole';
$config['tables'] = [];
$config['tick'] = false;
$config['swoole']['command'] = dirname(__DIR__).'/parent-server.php';
$config['swoole']['options']['dispatch_mode'] = 1;
$config['swoole']['options']['task_worker_num'] = 0;

return $config;
