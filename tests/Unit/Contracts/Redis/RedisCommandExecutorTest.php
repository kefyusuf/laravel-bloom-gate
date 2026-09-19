<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;

it('defines the framework-neutral redis command executor contract exactly', function (): void {
    $contract = new ReflectionClass(RedisCommandExecutor::class);

    expect($contract->isInterface())->toBeTrue();

    $method = $contract->getMethod('evaluate');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(3)
        ->and($parameters[0]->getName())->toBe('script')
        ->and($parameters[1]->getName())->toBe('keys')
        ->and($parameters[2]->getName())->toBe('arguments');

    $types = [
        'script' => [$parameters[0]->getType(), 'string'],
        'keys' => [$parameters[1]->getType(), 'array'],
        'arguments' => [$parameters[2]->getType(), 'array'],
        'return' => [$method->getReturnType(), 'int'],
    ];

    foreach ($types as $label => [$type, $expected]) {
        if (! $type instanceof ReflectionNamedType) {
            throw new RuntimeException(sprintf('Expected [%s] to have a named type.', $label));
        }

        expect($type->getName())->toBe($expected);
    }
});
