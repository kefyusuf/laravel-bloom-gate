<?php

declare(strict_types=1);

$root = $argv[1] ?? throw new InvalidArgumentException('Pass the consumer directory.');
$reference = $argv[2] ?? throw new InvalidArgumentException('Pass the expected commit.');
$contents = file_get_contents($root.'/vendor/composer/installed.json');

if ($contents === false) {
    throw new RuntimeException('Installed Composer metadata is unavailable.');
}

/** @var array{packages: list<array{name: string, dist?: array{type?: string, reference?: string}, 'installation-source'?: string}>} $installed */
$installed = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

foreach ($installed['packages'] as $package) {
    if ($package['name'] !== 'kefyusuf/laravel-bloom-gate') {
        continue;
    }

    if (($package['installation-source'] ?? null) !== 'dist'
        || ($package['dist']['type'] ?? null) !== 'zip'
        || ($package['dist']['reference'] ?? null) !== $reference) {
        throw new RuntimeException('Consumer did not install the expected commit ZIP through Composer dist.');
    }

    echo "PASS: Composer installed the expected commit ZIP through dist.\n";
    exit(0);
}

throw new RuntimeException('Consumer package is missing.');
