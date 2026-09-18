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
        'use Illuminate\\',
        'use Predis\\',
        'use Symfony\\',
        "use Redis;\n",
        "use RedisCluster;\n",
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

it('keeps internal package dependencies pointing inward', function (): void {
    $forbiddenByLayer = [
        'Core' => ['Contracts', 'Lifecycle', 'Application', 'Drivers', 'Laravel'],
        'Contracts' => ['Lifecycle', 'Application', 'Drivers', 'Laravel'],
        'Lifecycle' => ['Application', 'Drivers', 'Laravel'],
        'Application' => ['Drivers', 'Laravel'],
        'Drivers' => ['Lifecycle', 'Application', 'Laravel'],
    ];

    foreach ($forbiddenByLayer as $layer => $forbiddenLayers) {
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

            foreach ($forbiddenLayers as $forbiddenLayer) {
                expect($contents)->not->toContain(
                    'Kefyusuf\\BloomGate\\'.$forbiddenLayer.'\\',
                );
            }
        }
    }
});

it('allows only declared imports in framework-neutral layers', function (): void {
    $allowedPrefixesByLayer = [
        'Core' => [],
        'Contracts' => [
            'Kefyusuf\\BloomGate\\Core\\',
        ],
        'Lifecycle' => [
            'Kefyusuf\\BloomGate\\Core\\',
            'Kefyusuf\\BloomGate\\Contracts\\',
        ],
        'Application' => [
            'Kefyusuf\\BloomGate\\Core\\',
            'Kefyusuf\\BloomGate\\Contracts\\',
            'Kefyusuf\\BloomGate\\Lifecycle\\',
        ],
        'Drivers' => [
            'Kefyusuf\\BloomGate\\Core\\',
            'Kefyusuf\\BloomGate\\Contracts\\',
        ],
    ];

    foreach ($allowedPrefixesByLayer as $layer => $allowedPrefixes) {
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
            preg_match_all('/^use\\s+([^;]+);$/m', $contents, $matches);

            foreach ($matches[1] as $import) {
                $import = preg_replace(
                    '/^(?:function|const)\\s+/',
                    '',
                    trim((string) $import),
                ) ?? '';

                if (! str_contains($import, '\\')) {
                    continue;
                }

                $allowed = false;

                foreach ($allowedPrefixes as $allowedPrefix) {
                    if (str_starts_with($import, $allowedPrefix)) {
                        $allowed = true;
                        break;
                    }
                }

                expect($allowed)->toBeTrue(
                    sprintf(
                        'Disallowed import [%s] in framework-neutral layer [%s] at [%s].',
                        $import,
                        $layer,
                        $file->getPathname(),
                    ),
                );
            }
        }
    }
});
