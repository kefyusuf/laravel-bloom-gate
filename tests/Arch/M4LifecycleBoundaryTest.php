<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function m4ArchitecturePhpFiles(string $layer): array
{
    $root = realpath(__DIR__.'/../../src/'.$layer);

    if ($root === false) {
        throw new RuntimeException(sprintf(
            'Expected source layer [%s] to exist.',
            $layer,
        ));
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function m4ArchitectureImports(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf(
            'Unable to read architecture source file [%s].',
            $path,
        ));
    }

    preg_match_all('/^use\s+([^;]+);$/m', $contents, $matches);

    $imports = [];

    foreach ($matches[1] as $import) {
        $normalized = preg_replace(
            '/^(?:function|const)\s+/',
            '',
            trim((string) $import),
        );

        if ($normalized === null) {
            throw new RuntimeException(sprintf(
                'Unable to normalize import in [%s].',
                $path,
            ));
        }

        $imports[] = $normalized;
    }

    return $imports;
}

it('enforces the explicit m4 internal dependency graph', function (): void {
    $allowedInternalPrefixes = [
        'Core' => [],
        'Contracts' => [
            'Kefyusuf\\BloomGate\\Core\\',
        ],
        'Lifecycle' => [
            'Kefyusuf\\BloomGate\\Core\\',
            'Kefyusuf\\BloomGate\\Contracts\\',
        ],
        'Drivers' => [
            'Kefyusuf\\BloomGate\\Core\\',
            'Kefyusuf\\BloomGate\\Contracts\\',
        ],
    ];

    foreach ($allowedInternalPrefixes as $layer => $allowedPrefixes) {
        foreach (m4ArchitecturePhpFiles($layer) as $path) {
            foreach (m4ArchitectureImports($path) as $import) {
                if (! str_starts_with($import, 'Kefyusuf\\BloomGate\\')) {
                    continue;
                }

                $allowed = false;

                foreach ($allowedPrefixes as $allowedPrefix) {
                    if (str_starts_with($import, $allowedPrefix)) {
                        $allowed = true;
                        break;
                    }
                }

                expect($allowed)->toBeTrue(sprintf(
                    'M4 layer [%s] has disallowed internal import [%s] at [%s].',
                    $layer,
                    $import,
                    $path,
                ));
            }
        }
    }
});

it('explicitly keeps drivers and lifecycle independent in both directions', function (): void {
    foreach (m4ArchitecturePhpFiles('Drivers') as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read [%s].', $path));
        }

        expect($contents)->not->toContain('Kefyusuf\\BloomGate\\Lifecycle\\');
    }

    foreach (m4ArchitecturePhpFiles('Lifecycle') as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read [%s].', $path));
        }

        expect($contents)->not->toContain('Kefyusuf\\BloomGate\\Drivers\\');
    }
});

it('keeps every non laravel m4 layer free from illuminate imports', function (): void {
    foreach (['Core', 'Contracts', 'Lifecycle', 'Drivers'] as $layer) {
        foreach (m4ArchitecturePhpFiles($layer) as $path) {
            foreach (m4ArchitectureImports($path) as $import) {
                expect(str_starts_with($import, 'Illuminate\\'))->toBeFalse(sprintf(
                    'Illuminate import [%s] escaped the Laravel adapter boundary at [%s].',
                    $import,
                    $path,
                ));
            }
        }
    }
});

it('restricts laravel source dependencies to internal package layers and illuminate', function (): void {
    foreach (m4ArchitecturePhpFiles('Laravel') as $path) {
        foreach (m4ArchitectureImports($path) as $import) {
            if (! str_contains($import, '\\')) {
                continue;
            }

            $allowed = str_starts_with($import, 'Illuminate\\')
                || str_starts_with($import, 'Kefyusuf\\BloomGate\\');

            expect($allowed)->toBeTrue(sprintf(
                'Laravel adapter has undeclared external import [%s] at [%s].',
                $import,
                $path,
            ));
        }
    }
});

it('keeps m4 core state objects free from persistence and redis knowledge', function (): void {
    $coreRoot = realpath(__DIR__.'/../../src/Core');

    if ($coreRoot === false) {
        throw new RuntimeException('Expected Core source directory.');
    }

    $stateFiles = [
        'LifecycleState.php',
        'HealthState.php',
        'FilterStateRevision.php',
        'GenerationControlState.php',
        'FilterControlState.php',
    ];

    $forbiddenTokens = [
        'control-v1',
        'redis-bitmap-v1',
        'Redis',
        'last_allocated_version',
        'active_version',
        'candidate_version',
        ':state',
        ':meta',
        ':bf',
        'HSET',
        'EVAL',
    ];

    foreach ($stateFiles as $stateFile) {
        $path = $coreRoot.DIRECTORY_SEPARATOR.$stateFile;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf(
                'Unable to read M4 Core state object [%s].',
                $path,
            ));
        }

        foreach ($forbiddenTokens as $forbiddenToken) {
            expect($contents)->not->toContain(
                $forbiddenToken,
                sprintf(
                    'Persistence token [%s] leaked into Core state object [%s].',
                    $forbiddenToken,
                    $stateFile,
                ),
            );
        }
    }
});
