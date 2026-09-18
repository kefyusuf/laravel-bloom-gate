<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Laravel\BloomGateServiceProvider;

it('declares the service provider for Laravel package discovery', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__.'/../../../composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (! is_array($composer)) {
        throw new RuntimeException('composer.json must decode to an array.');
    }

    $extra = $composer['extra'] ?? null;

    if (! is_array($extra)) {
        throw new RuntimeException('composer.json must define an extra object.');
    }

    $laravel = $extra['laravel'] ?? null;

    if (! is_array($laravel)) {
        throw new RuntimeException('composer.json must define extra.laravel.');
    }

    $providers = $laravel['providers'] ?? [];
    $aliases = $laravel['aliases'] ?? [];

    if (! is_array($providers) || ! is_array($aliases)) {
        throw new RuntimeException('Laravel discovery providers and aliases must be arrays.');
    }

    expect($providers)
        ->toContain(BloomGateServiceProvider::class)
        ->and($aliases)
        ->toBe([]);
});
