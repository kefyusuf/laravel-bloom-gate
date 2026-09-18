<?php

declare(strict_types=1);

it('keeps Illuminate dependencies inside the Laravel adapter boundary', function (): void {
    $root = realpath(__DIR__.'/../../src');

    expect($root)->not->toBeFalse();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator((string) $root),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        if (str_contains($path, DIRECTORY_SEPARATOR.'Laravel'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        expect((string) file_get_contents($path))
            ->not->toContain('Illuminate\\');
    }
});
