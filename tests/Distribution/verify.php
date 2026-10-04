<?php

declare(strict_types=1);

$root = $argv[1] ?? throw new InvalidArgumentException('Pass the extracted distribution directory.');

foreach (['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'src/Laravel/BloomGateServiceProvider.php', 'config/bloom-gate.php', 'docs/architecture/laravel-coordination.md'] as $required) {
    if (! is_file($root.'/'.$required)) {
        throw new RuntimeException('Distribution is missing: '.$required);
    }
}

foreach (['tests', '.github', '.build', '.git', 'vendor', 'composer.lock', 'testbench.yaml', 'phpunit.xml.dist', 'phpstan.neon.dist', 'pint.json', '.gitignore', '.gitattributes', '.editorconfig'] as $excluded) {
    if (file_exists($root.'/'.$excluded)) {
        throw new RuntimeException('Distribution contains development material: '.$excluded);
    }
}

echo "PASS: distribution contains runtime and operator documentation without development material.\n";
