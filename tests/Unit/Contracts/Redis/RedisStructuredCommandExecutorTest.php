<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;

it('defines the structured redis executor as an additive child contract', function (): void {
    $contract = new ReflectionClass(RedisStructuredCommandExecutor::class);

    expect($contract->isInterface())->toBeTrue()
        ->and($contract->implementsInterface(RedisCommandExecutor::class))->toBeTrue();

    $method = $contract->getMethod('evaluateStructured');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(3)
        ->and($parameters[0]->getName())->toBe('script')
        ->and($parameters[1]->getName())->toBe('keys')
        ->and($parameters[2]->getName())->toBe('arguments');

    foreach ([
        [$parameters[0]->getType(), 'string'],
        [$parameters[1]->getType(), 'array'],
        [$parameters[2]->getType(), 'array'],
        [$method->getReturnType(), 'array'],
    ] as [$type, $expected]) {
        expect($type)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($type->getName())->toBe($expected);
    }

    $legacy = $contract->getMethod('evaluate');

    expect($legacy->getReturnType())->toBeInstanceOf(ReflectionNamedType::class)
        ->and($legacy->getReturnType()?->getName())->toBe('int');
});
