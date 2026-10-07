<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Tests\Experiments\Swoole\GeneratorDeadline;

if (is_file(__DIR__.'/GeneratorDeadline.php')) {
    require_once __DIR__.'/GeneratorDeadline.php';
}

it('holds the response until its monotonic deadline even when the timer wakes early', function (): void {
    $now = 0;
    $callbacks = [];
    $sent = [];
    GeneratorDeadline::after(25, function () use (&$now): int {
        return $now;
    }, function (int $milliseconds, Closure $callback) use (&$callbacks): void {
        $callbacks[] = $callback;
    }, function () use (&$sent, &$now): void {
        $sent[] = $now;
    });
    $now = 24021032;
    $wake = array_shift($callbacks);
    if (! $wake instanceof Closure) {
        throw new RuntimeException('Timer callback was not scheduled.');
    }
    $wake();
    expect($sent)->toBe([]);
    $now = 25000000;
    $wake = array_shift($callbacks);
    if (! $wake instanceof Closure) {
        throw new RuntimeException('Early wake did not schedule another callback.');
    }
    $wake();
    expect($sent)->toBe([25000000]);
});

it('releases response state without waiting for cyclic collection', function (int $delay): void {
    $state = new stdClass;
    $reference = WeakReference::create($state);
    GeneratorDeadline::after($delay, static fn (): int => 0,
        static function (int $milliseconds, Closure $callback): void {
            // External timer allocation failed and retained no callback.
        }, static function (): void {});
    unset($state);
    expect($reference->get())->toBeNull();
})->with(['completed' => [0], 'failed allocation' => [25]]);
