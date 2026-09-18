<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests;

use Kefyusuf\BloomGate\Laravel\BloomGateServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BloomGateServiceProvider::class,
        ];
    }
}
