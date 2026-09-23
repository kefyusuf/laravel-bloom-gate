<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;

it('defines bulk bloom driver as an additive child capability', function (): void {
    $contract = new ReflectionClass(BulkBloomDriver::class);

    expect($contract->isInterface())->toBeTrue()
        ->and($contract->implementsInterface(BloomDriver::class))->toBeTrue();

    $method = $contract->getMethod('addMany');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(3)
        ->and($parameters[0]->getName())->toBe('name')
        ->and($parameters[1]->getName())->toBe('version')
        ->and($parameters[2]->getName())->toBe('items');

    foreach ([
        [$parameters[0]->getType(), FilterName::class],
        [$parameters[1]->getType(), FilterVersion::class],
        [$parameters[2]->getType(), 'array'],
        [$method->getReturnType(), 'void'],
    ] as [$type, $expected]) {
        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException('Expected BulkBloomDriver::addMany types to be named.');
        }

        expect($type->getName())->toBe($expected);
    }

    $source = file_get_contents(__DIR__.'/../../../src/Contracts/BulkBloomDriver.php');

    if ($source === false) {
        throw new RuntimeException('Unable to read BulkBloomDriver source.');
    }

    expect($source)->toContain('@param  list<BitPositions>  $items')
        ->and($source)->toContain('use Kefyusuf\\BloomGate\\Core\\BitPositions;');
});
