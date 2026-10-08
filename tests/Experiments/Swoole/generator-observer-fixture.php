<?php

declare(strict_types=1);

// Synthetic loopback responses for the actual observer CLI tests only.
header('Content-Type: application/json');
$uri = $_SERVER['REQUEST_URI'] ?? '';
if (! is_string($uri)) {
    throw new RuntimeException('Synthetic request URI unavailable.');
}
if (str_starts_with($uri, '/delayed/')) {
    usleep(750000);
}
if (str_starts_with($uri, '/flaky-receiver/')) {
    $state = getenv('GENERATOR_OBSERVER_FIXTURE_STATE');
    if ($state === false) {
        throw new RuntimeException('Synthetic receiver state missing.');
    }
    $count = (int) file_get_contents($state);
    file_put_contents($state, (string) ($count + 1));
    if ($count > 0) {
        echo '{';

        return;
    }
}
if (str_starts_with($uri, '/invalid-json/')) {
    echo '{';

    return;
}
if (str_starts_with($uri, '/invalid-schema/')) {
    echo '{}';

    return;
}
if (str_starts_with($uri, '/invalid-schema-receiver/')) {
    echo '7';

    return;
}
$body = json_encode(['data' => [['id' => 'iterations', 'sample' => ['count' => 7]]]], JSON_THROW_ON_ERROR);
if (str_starts_with($uri, '/at-limit/')) {
    $body = str_pad($body, 524288, ' ');
}
if (str_starts_with($uri, '/oversized/')) {
    $body = str_pad($body, 524289, ' ');
}
echo $body;
