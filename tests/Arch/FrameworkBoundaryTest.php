<?php

declare(strict_types=1);

it('keeps framework-neutral layers free from framework and backend imports', function (): void {
    $layers = [
        'Core',
        'Contracts',
        'Lifecycle',
        'Application',
        'Drivers',
    ];

    $forbiddenImports = [
        'use Illuminate\\\\',
        'use Predis\\\\',
        'use Symfony\\\\',
        "use Redis;\\n",
        "use RedisCluster;\\n",
    ];

    foreach ($layers as $layer) {
        $root = __DIR__.'/../../src/'.$layer;

        if (is_dir($root) === false) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($forbiddenImports as $forbiddenImport) {
                expect($contents)->not->toContain($forbiddenImport);
            }
        }
    }
});
