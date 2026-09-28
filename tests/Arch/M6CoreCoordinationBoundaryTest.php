<?php

declare(strict_types=1);

it('keeps WU-00 coordination Core free from framework and persistence knowledge', function (): void {
    $coreRoot = realpath(__DIR__.'/../../src/Core');

    if ($coreRoot === false) {
        throw new RuntimeException('Expected Core source directory.');
    }

    $files = [
        'AuthoritativeOutcome.php',
        'SynchronizationEpoch.php',
        'SynchronizationPhase.php',
        'SynchronizationRevision.php',
        'SynchronizationState.php',
        'SynchronizationTargetSet.php',
        'WriterLease.php',
        'WriterLeaseState.php',
        'WriterLeaseToken.php',
    ];

    $forbiddenTokens = [
        'Illuminate\\',
        'Kefyusuf\\BloomGate\\Contracts\\',
        'Kefyusuf\\BloomGate\\Lifecycle\\',
        'Kefyusuf\\BloomGate\\Application\\',
        'Kefyusuf\\BloomGate\\Drivers\\',
        'Kefyusuf\\BloomGate\\Laravel\\',
        'Redis',
        'control-v1',
        'sync-v1',
        ':state',
        ':sync',
        ':meta',
        ':bf',
        'HSET',
        'EVAL',
    ];

    foreach ($files as $file) {
        $path = $coreRoot.DIRECTORY_SEPARATOR.$file;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf(
                'Unable to read WU-00 Core source file [%s].',
                $path,
            ));
        }

        foreach ($forbiddenTokens as $forbiddenToken) {
            expect($contents)->not->toContain(
                $forbiddenToken,
                sprintf(
                    'Framework/persistence token [%s] leaked into WU-00 Core source [%s].',
                    $forbiddenToken,
                    $file,
                ),
            );
        }
    }
});
