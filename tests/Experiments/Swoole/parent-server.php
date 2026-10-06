<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/ParentRuntime.php';

ParentRuntime::initialize();

require __DIR__.'/vendor/laravel/octane/bin/swoole-server';
