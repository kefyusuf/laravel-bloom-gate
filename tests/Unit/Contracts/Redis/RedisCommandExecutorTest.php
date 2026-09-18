<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use ReflectionClass;
use ReflectionNamedType;

it('defines the framework-neutral redis command executor contract exactly', function (): void {
    $contract = new ReflectionClass(RedisCommandExecutor::class);

    expect($contract->isInterface())->toBeTrue();

    $method = $contract->getMethod('evaluate');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(3)
        ->and($parameters[0]->getName())->toBe('script')
        ->and($parameters[1]->getName())->toBe('keys')
        ->and($parameters[2]->getName())->toBe('arguments');

    $scriptType = $parameters[0]->getType();
    $keysType = $parameters[1]->getType();
    $argumentsType = $parameters[2]->getType();
    $returnType = $method->getReturnType();

    expect($scriptType)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($scriptType->getName())->toBe('string')
        ->and($keysType)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($keysType->getName())->toBe('array')
        ->and($argumentsType)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($argumentsType->getName())->toBe('array')
        ->and($returnType)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($returnType->getName())->toBe('int');
});
