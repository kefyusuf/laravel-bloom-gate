<?php

declare(strict_types=1);

it('keeps Core free from framework and infrastructure imports', function (): void {
    $root = realpath(__DIR__.'/../../src/Core');

    expect($root)->not->toBeFalse();

    $forbiddenImports = [
        'use Illuminate\\',
        'use Predis\\',
        'use Symfony\\',
        "use Redis;\n",
        "use RedisCluster;\n",
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator((string) $root),
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
});
