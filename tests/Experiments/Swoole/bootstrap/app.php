<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Laravel\Octane\OctaneServiceProvider;

require_once dirname(__DIR__).'/ParentRuntime.php';

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([OctaneServiceProvider::class])
    ->withRouting(using: static function (): void {
        require dirname(__DIR__).'/routes.php';
    })
    ->withMiddleware()
    ->withExceptions()
    ->create();
