<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function m5ArchitecturePhpFiles(string $relativeRoot): array
{
    $root = realpath(__DIR__.'/../../src/'.$relativeRoot);

    if ($root === false) {
        throw new RuntimeException(sprintf(
            'Expected M5 architecture source root [%s] to exist.',
            $relativeRoot,
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
function m5ArchitectureImports(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf(
            'Unable to read M5 architecture source file [%s].',
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
                'Unable to normalize M5 architecture import in [%s].',
                $path,
            ));
        }

        $imports[] = $normalized;
    }

    return $imports;
}

function m5ArchitectureSource(string $relativePath): string
{
    $path = __DIR__.'/../../src/'.$relativePath;
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf(
            'Unable to read M5 architecture source [%s].',
            $relativePath,
        ));
    }

    return $contents;
}

it('enforces the complete m5 internal dependency direction', function (): void {
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
        'Application' => [
            'Kefyusuf\\BloomGate\\Core\\',
            'Kefyusuf\\BloomGate\\Contracts\\',
            'Kefyusuf\\BloomGate\\Lifecycle\\',
        ],
    ];

    foreach ($allowedInternalPrefixes as $layer => $allowedPrefixes) {
        foreach (m5ArchitecturePhpFiles($layer) as $path) {
            foreach (m5ArchitectureImports($path) as $import) {
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
                    'M5 layer [%s] has disallowed internal import [%s] at [%s].',
                    $layer,
                    $import,
                    $path,
                ));
            }
        }
    }
});

it('keeps application and contracts framework neutral', function (): void {
    foreach (['Application', 'Contracts'] as $layer) {
        foreach (m5ArchitecturePhpFiles($layer) as $path) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException(sprintf(
                    'Unable to read M5 framework-neutral source [%s].',
                    $path,
                ));
            }

            expect($contents)->not->toContain(
                'Illuminate\\',
                sprintf(
                    'Illuminate escaped the Laravel boundary into M5 layer [%s] at [%s].',
                    $layer,
                    $path,
                ),
            );
        }
    }
});

it('keeps drivers independent from application and lifecycle', function (): void {
    foreach (m5ArchitecturePhpFiles('Drivers') as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf(
                'Unable to read M5 driver source [%s].',
                $path,
            ));
        }

        expect($contents)->not->toContain(
            'Kefyusuf\\BloomGate\\Application\\',
            sprintf('Driver depends on Application at [%s].', $path),
        )->not->toContain(
            'Kefyusuf\\BloomGate\\Lifecycle\\',
            sprintf('Driver depends on Lifecycle at [%s].', $path),
        );
    }
});

it('keeps laravel validation facade and console surfaces inside the adapter boundary', function (): void {
    foreach ([
        'Laravel/Validation',
        'Laravel/Facades',
        'Laravel/Console',
    ] as $surface) {
        $files = m5ArchitecturePhpFiles($surface);

        expect($files)->not->toBeEmpty();

        foreach ($files as $path) {
            foreach (m5ArchitectureImports($path) as $import) {
                if (! str_contains($import, '\\')) {
                    continue;
                }

                $allowed = str_starts_with($import, 'Illuminate\\')
                    || str_starts_with($import, 'Kefyusuf\\BloomGate\\');

                expect($allowed)->toBeTrue(sprintf(
                    'Laravel M5 adapter surface [%s] imports undeclared external dependency [%s] at [%s].',
                    $surface,
                    $import,
                    $path,
                ));
            }
        }
    }
});

it('keeps core identity fingerprint and layout values free from persistence tokens', function (): void {
    $files = [
        'Core/NormalizationIdentity.php',
        'Core/AuthoritativeSetIdentity.php',
        'Core/NormalizationFingerprint.php',
        'Core/AuthoritativeSetFingerprint.php',
        'Core/ConsistencyFingerprint.php',
        'Core/ConsistencyContract.php',
        'Core/BloomLayout.php',
        'Core/ProbeAlgorithm.php',
        'Core/SemanticFingerprintCalculator.php',
    ];

    $forbiddenTokens = [
        'control-v1',
        'redis-bitmap-v1',
        'managed_bitmap_written',
        'last_allocated_version',
        'active_version',
        'candidate_version',
        ':state',
        ':meta',
        ':bf',
        'HSET',
        'EVAL',
        'RedisKeyspace',
        'Illuminate\\',
        'Kefyusuf\\BloomGate\\Laravel\\',
        'Kefyusuf\\BloomGate\\Drivers\\Redis\\',
    ];

    foreach ($files as $file) {
        $contents = m5ArchitectureSource($file);

        foreach ($forbiddenTokens as $token) {
            expect($contents)->not->toContain(
                $token,
                sprintf(
                    'Persistence token [%s] leaked into M5 Core identity/layout value [%s].',
                    $token,
                    $file,
                ),
            );
        }
    }
});

it('keeps query gate on query safety and authorized probe abstractions', function (): void {
    $gate = m5ArchitectureSource('Application/QueryGate.php');
    $resolver = m5ArchitectureSource(
        'Application/QuerySafetyDescriptorResolver.php',
    );

    expect($gate)
        ->toContain('use Kefyusuf\\BloomGate\\Contracts\\AuthorizedProbe;')
        ->toContain('private AuthorizedProbe $authorizedProbe')
        ->toContain('private QuerySafetyDescriptorResolver $resolver')
        ->not->toContain('Kefyusuf\\BloomGate\\Drivers\\Redis\\')
        ->not->toContain('Kefyusuf\\BloomGate\\Laravel\\')
        ->not->toContain('RedisAuthorizedProbe')
        ->not->toContain('RedisQuerySafetyScripts');

    expect($resolver)
        ->toContain('use Kefyusuf\\BloomGate\\Contracts\\ActiveGenerationSnapshotReader;')
        ->toContain('use Kefyusuf\\BloomGate\\Contracts\\GenerationContractStore;')
        ->not->toContain('Kefyusuf\\BloomGate\\Drivers\\Redis\\')
        ->not->toContain('Kefyusuf\\BloomGate\\Laravel\\');
});

it('keeps filter definition contracts framework neutral', function (): void {
    $definition = m5ArchitectureSource('Contracts/FilterDefinition.php');

    expect($definition)
        ->toContain('interface FilterDefinition')
        ->toContain('public function normalizer(): ValueNormalizer;')
        ->toContain('public function authoritativeSet(): AuthoritativeSet;')
        ->toContain('public function consistency(): ConsistencyContract;')
        ->not->toContain('Illuminate\\')
        ->not->toContain('Kefyusuf\\BloomGate\\Laravel\\')
        ->not->toContain('Kefyusuf\\BloomGate\\Drivers\\');
});
