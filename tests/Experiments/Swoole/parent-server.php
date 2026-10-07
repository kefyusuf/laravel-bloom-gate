<?php

declare(strict_types=1);
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\MeasurementRuntime;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedMemoryQuery;

require __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/fixture.php';
require_once __DIR__.'/ParentRuntime.php';

ParentRuntime::initialize();

if (getenv('SHARED_MEMORY_PUBLISH') === '1') {
    $domain = ParentRuntime::$domain ?? throw new RuntimeException('No parent domain.');
    (new SharedMemoryQuery($domain))->publishDataset();
    if (getenv('SHARED_MEMORY_MEASURE') === '1') {
        require_once __DIR__.'/MeasurementRuntime.php';
        MeasurementRuntime::publishRedis($domain);
    }
}

require __DIR__.'/vendor/laravel/octane/bin/swoole-server';
