<?php

declare(strict_types=1);
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedMemoryQuery;

require __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/fixture.php';
require_once __DIR__.'/ParentRuntime.php';

ParentRuntime::initialize();

if (getenv('SHARED_MEMORY_PUBLISH') === '1') {
    $domain = ParentRuntime::$domain ?? throw new RuntimeException('No parent domain.');
    (new SharedMemoryQuery($domain))->publishDataset();
}

require __DIR__.'/vendor/laravel/octane/bin/swoole-server';
