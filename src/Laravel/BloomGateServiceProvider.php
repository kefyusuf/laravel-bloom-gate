<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel;

use Illuminate\Support\ServiceProvider;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Laravel\Redis\RedisTrustedNegativeProfileResolver;

final class BloomGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/bloom-gate.php',
            'bloom-gate',
        );

        $this->app->singleton(
            FilterDefinitionResolver::class,
        );
        $this->app->singleton(
            FilterRegistry::class,
            ConfigFilterRegistry::class,
        );
        $this->app->singleton(
            RedisTrustedNegativeProfileResolver::class,
        );
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/bloom-gate.php' => config_path('bloom-gate.php'),
        ], 'bloom-gate-config');
    }
}
