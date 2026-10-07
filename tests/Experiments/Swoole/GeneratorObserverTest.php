<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('retains an unavailable observation instead of inventing zero lost work', function (): void {
    $output = tempnam(sys_get_temp_dir(), 'generator-observer-');
    if ($output === false) {
        throw new RuntimeException('Unable to allocate observation output.');
    }
    unlink($output);
    try {
        $observer = new Process(['php', __DIR__.'/generator-observe.php', 'http://127.0.0.1:1', $output, '1']);
        $observer->run();
        expect($observer->getExitCode())->toBe(1);
        $body = file_get_contents($output);
        if ($body === false || $body === '') {
            throw new RuntimeException('Unavailable observation was not retained.');
        }
        $row = json_decode(trim($body), true, 512, JSON_THROW_ON_ERROR);
        expect($row)->toMatchArray(['status' => 'unavailable', 'k6' => null, 'receiver' => null]);
    } finally {
        if (is_file($output)) {
            unlink($output);
        }
    }
});
