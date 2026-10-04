<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Kefyusuf\BloomGate\Application\CoordinatedWriter;
use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Application\OnlineRebuildCoordinator;
use Kefyusuf\BloomGate\Laravel\BloomGateServiceProvider;
use Orchestra\Testbench\TestCase;

require __DIR__.'/vendor/autoload.php';

function runDistributionSmoke(): void
{
    foreach (['bootstrap/cache', 'config', 'storage/framework/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
        if (! is_dir(__DIR__.'/'.$directory)) {
            mkdir(__DIR__.'/'.$directory, 0777, true);
        }
    }

    $app = Application::configure(basePath: __DIR__)->withExceptions()->create();
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    if (getenv('DISTRIBUTION_SMOKE_FORCE_FAILURE') === '1') {
        throw new RuntimeException('Injected smoke failure after Laravel bootstrap.');
    }

    if (! in_array(BloomGateServiceProvider::class, $app->make(PackageManifest::class)->providers(), true)) {
        throw new RuntimeException('Package discovery did not register the provider.');
    }

    if (class_exists(TestCase::class) || class_exists(PHPUnit\Framework\TestCase::class)) {
        throw new RuntimeException('Consumer unexpectedly contains development test dependencies.');
    }

    $app['config']->set('bloom-gate.default', 'memory');

    foreach ([CoordinatedWriter::class, CoordinationStatusReader::class, OnlineRebuildCoordinator::class] as $service) {
        $app->make($service);
    }

    $commands = $kernel->all();

    foreach (['bloom:status', 'bloom:doctor', 'bloom:coordinate:adopt', 'bloom:rebuild', 'bloom:rebuild:abort', 'bloom:lease:resolve'] as $command) {
        if (! isset($commands[$command])) {
            throw new RuntimeException('Command was not discovered: '.$command);
        }
    }

    if ($kernel->call('bloom:status') !== 0 || ! str_contains($kernel->output(), 'No Bloom Gate filters configured.')) {
        throw new RuntimeException('Status command did not execute correctly.');
    }

    echo "PASS: archive-backed production-only discovery, service resolution and command execution.\n";
}

try {
    runDistributionSmoke();
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
