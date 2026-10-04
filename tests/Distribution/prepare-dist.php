<?php

declare(strict_types=1);

$manifestPath = $argv[1] ?? throw new InvalidArgumentException('Pass the archived package manifest.');
$consumerPath = $argv[2] ?? throw new InvalidArgumentException('Pass the consumer directory.');
$url = $argv[3] ?? throw new InvalidArgumentException('Pass the ZIP URL.');
$reference = $argv[4] ?? throw new InvalidArgumentException('Pass the commit reference.');

if (preg_match('/\A[0-9a-f]{40}\z/', $reference) !== 1) {
    throw new InvalidArgumentException('A full commit SHA is required.');
}

$manifestContents = file_get_contents($manifestPath);
$fixtureContents = file_get_contents(__DIR__.'/composer.json');

if ($manifestContents === false || $fixtureContents === false) {
    throw new RuntimeException('Package or consumer manifest is unavailable.');
}

/** @var array<string, mixed> $package */
$package = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
/** @var array<string, mixed> $consumer */
$consumer = json_decode($fixtureContents, true, flags: JSON_THROW_ON_ERROR);
$package['version'] = 'dev-main';
$package['dist'] = ['type' => 'zip', 'url' => $url, 'reference' => $reference];
unset($package['source']);
$consumer['repositories'] = [['type' => 'package', 'package' => $package]];

if (file_put_contents($consumerPath.'/composer.json', json_encode($consumer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL) === false) {
    throw new RuntimeException('Could not write the isolated dist consumer manifest.');
}
