<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;

/**
 * @template T of object
 *
 * @param  ReflectionClass<T>  $contract
 * @return list<string>
 */
function m5ContractPublicMethodNames(ReflectionClass $contract): array
{
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $contract->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($methods);

    return $methods;
}

function m5ContractNamedTypeName(?ReflectionType $type, string $label): string
{
    if ($type instanceof ReflectionNamedType) {
        return $type->getName();
    }

    throw new RuntimeException(sprintf(
        'Expected [%s] to use one named type.',
        $label,
    ));
}

/**
 * @return list<string>
 */
function m5ContractUnionTypeNames(?ReflectionType $type, string $label): array
{
    if ($type instanceof ReflectionUnionType) {
        $union = $type;
    } else {
        throw new RuntimeException(sprintf(
            'Expected [%s] to use a union type.',
            $label,
        ));
    }

    $names = array_map(
        static function (ReflectionType $member) use ($label): string {
            if ($member instanceof ReflectionNamedType) {
                return $member->getName();
            }

            throw new RuntimeException(sprintf(
                'Expected every [%s] union member to be a named type.',
                $label,
            ));
        },
        $union->getTypes(),
    );

    sort($names);

    return $names;
}

it('defines the value normalizer contract exactly', function (): void {
    $contract = new ReflectionClass(ValueNormalizer::class);

    expect($contract->isInterface())->toBeTrue()
        ->and(m5ContractPublicMethodNames($contract))->toBe([
            'identity',
            'normalize',
        ]);

    $identity = $contract->getMethod('identity');

    expect($identity->getNumberOfParameters())->toBe(0)
        ->and(m5ContractNamedTypeName(
            $identity->getReturnType(),
            'ValueNormalizer::identity return',
        ))->toBe(NormalizationIdentity::class);

    $normalize = $contract->getMethod('normalize');
    $parameters = $normalize->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getName())->toBe('value')
        ->and(m5ContractUnionTypeNames(
            $parameters[0]->getType(),
            'ValueNormalizer::normalize value',
        ))->toBe([
            'int',
            'string',
        ])
        ->and(m5ContractNamedTypeName(
            $normalize->getReturnType(),
            'ValueNormalizer::normalize return',
        ))->toBe(NormalizedValue::class);
});

it('defines the authoritative set contract exactly', function (): void {
    $contract = new ReflectionClass(AuthoritativeSet::class);

    expect($contract->isInterface())->toBeTrue()
        ->and(m5ContractPublicMethodNames($contract))->toBe([
            'exists',
            'identity',
            'values',
        ]);

    $identity = $contract->getMethod('identity');

    expect($identity->getNumberOfParameters())->toBe(0)
        ->and(m5ContractNamedTypeName(
            $identity->getReturnType(),
            'AuthoritativeSet::identity return',
        ))->toBe(AuthoritativeSetIdentity::class);

    $exists = $contract->getMethod('exists');
    $existsParameters = $exists->getParameters();

    expect($existsParameters)->toHaveCount(1)
        ->and($existsParameters[0]->getName())->toBe('value')
        ->and(m5ContractNamedTypeName(
            $existsParameters[0]->getType(),
            'AuthoritativeSet::exists value',
        ))->toBe(NormalizedValue::class)
        ->and(m5ContractNamedTypeName(
            $exists->getReturnType(),
            'AuthoritativeSet::exists return',
        ))->toBe('bool');

    $values = $contract->getMethod('values');

    expect($values->getNumberOfParameters())->toBe(0)
        ->and(m5ContractNamedTypeName(
            $values->getReturnType(),
            'AuthoritativeSet::values return',
        ))->toBe('iterable');
});

it('defines filter definition as exactly three semantic capabilities', function (): void {
    $contract = new ReflectionClass(FilterDefinition::class);

    expect($contract->isInterface())->toBeTrue()
        ->and(m5ContractPublicMethodNames($contract))->toBe([
            'authoritativeSet',
            'consistency',
            'normalizer',
        ]);

    $expectedReturns = [
        'normalizer' => ValueNormalizer::class,
        'authoritativeSet' => AuthoritativeSet::class,
        'consistency' => ConsistencyContract::class,
    ];

    foreach ($expectedReturns as $methodName => $expectedReturn) {
        $method = $contract->getMethod($methodName);

        expect($method->getNumberOfParameters())->toBe(0)
            ->and(m5ContractNamedTypeName(
                $method->getReturnType(),
                sprintf('FilterDefinition::%s return', $methodName),
            ))->toBe($expectedReturn);
    }
});

it('keeps filter definition contracts framework neutral and free from hidden query semantics', function (): void {
    $paths = [
        __DIR__.'/../../../src/Contracts/ValueNormalizer.php',
        __DIR__.'/../../../src/Contracts/AuthoritativeSet.php',
        __DIR__.'/../../../src/Contracts/FilterDefinition.php',
    ];

    $forbiddenTokens = [
        'Illuminate\\',
        'Eloquent',
        'Builder',
        'Closure',
        'callable',
        'FilterName',
        'BloomLayout',
        'Redis',
        'capacity',
        'false_positive',
        'falsePositiveRate',
        'driver',
    ];

    foreach ($paths as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf(
                'Unable to read M5 contract source [%s].',
                $path,
            ));
        }

        foreach ($forbiddenTokens as $token) {
            expect($contents)->not->toContain(
                $token,
                sprintf(
                    'Forbidden token [%s] leaked into M5 contract [%s].',
                    $token,
                    basename($path),
                ),
            );
        }
    }
});
