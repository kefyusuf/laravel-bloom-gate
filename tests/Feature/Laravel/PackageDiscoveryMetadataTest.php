<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Laravel\BloomGateServiceProvider;

it('declares the service provider for Laravel package discovery', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__.'/../../../composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['extra']['laravel']['providers'] ?? [])
        ->toContain(BloomGateServiceProvider::class)
        ->and($composer['extra']['laravel']['aliases'] ?? [])
        ->toBe([]);
});
