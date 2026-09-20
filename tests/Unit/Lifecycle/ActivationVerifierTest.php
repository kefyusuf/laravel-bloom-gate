<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;

function activationVerificationLayout(): BloomLayout
{
    return BloomLayout::create(1024, 7, ProbeAlgorithm::Sha256DoubleHashV1);
}

function activationThrowingDriver(Throwable $failure): BloomDriver
{
    return new class($failure) implements BloomDriver
    {
        public function __construct(
            private Throwable $failure,
        ) {}

        public function provision(
            FilterName $name,
            FilterVersion $version,
            BloomLayout $layout,
        ): void {}

        public function add(
            FilterName $name,
            FilterVersion $version,
            BitPositions $positions,
        ): void {}

        public function mightContain(
            FilterName $name,
            FilterVersion $version,
            BitPositions $positions,
        ): bool {
            throw $this->failure;
        }

        public function destroy(
            FilterName $name,
            FilterVersion $version,
        ): void {}
    };
}

it('passes when every authoritative present value might be contained', function (): void {
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(4);
    $layout = activationVerificationLayout();
    $generator = new BloomProbeGenerator;
    $driver = new MemoryBloomDriver;

    $values = [
        NormalizedValue::fromBytes('ABC-001'),
        NormalizedValue::fromBytes('ABC-002'),
        NormalizedValue::fromBytes("ABC\0-003"),
    ];

    $driver->provision($name, $version, $layout);

    foreach ($values as $value) {
        $driver->add($name, $version, $generator->generate($value, $layout));
    }

    $result = (new ActivationVerifier($generator, $driver))->verify(
        $name,
        $version,
        $layout,
        $values,
    );

    expect($result->status())->toBe(ActivationVerificationStatus::Passed)
        ->and($result->checkedCount())->toBe(3)
        ->and($result->filterName())->toBe($name)
        ->and($result->filterVersion())->toBe($version);
});

it('stops at the first false negative and counts the failing check', function (): void {
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(4);
    $layout = activationVerificationLayout();
    $driver = new MemoryBloomDriver;
    $driver->provision($name, $version, $layout);

    $stream = (static function (): Generator {
        yield NormalizedValue::fromBytes('missing-first');

        throw new RuntimeException('Verifier iterated beyond the first false negative.');
    })();

    $result = (new ActivationVerifier(new BloomProbeGenerator, $driver))->verify(
        $name,
        $version,
        $layout,
        $stream,
    );

    expect($result->status())->toBe(ActivationVerificationStatus::FalseNegativeDetected)
        ->and($result->checkedCount())->toBe(1);
});

it('allows an empty authoritative present stream to pass with zero checked values', function (): void {
    $result = (new ActivationVerifier(
        new BloomProbeGenerator,
        new MemoryBloomDriver,
    ))->verify(
        FilterName::fromString('products.sku'),
        FilterVersion::fromInt(1),
        activationVerificationLayout(),
        [],
    );

    expect($result->status())->toBe(ActivationVerificationStatus::Passed)
        ->and($result->checkedCount())->toBe(0);
});

it('propagates bloom driver operation failures without converting them to a result', function (): void {
    $failure = new BloomDriverOperationFailed('redis unavailable');

    expect(fn () => (new ActivationVerifier(
        new BloomProbeGenerator,
        activationThrowingDriver($failure),
    ))->verify(
        FilterName::fromString('products.sku'),
        FilterVersion::fromInt(1),
        activationVerificationLayout(),
        [NormalizedValue::fromBytes('ABC-001')],
    ))->toThrow(BloomDriverOperationFailed::class, 'redis unavailable');
});

it('propagates bloom storage corruption without converting it to a result', function (): void {
    $failure = new BloomStorageCorrupt('corrupt bitmap metadata');

    expect(fn () => (new ActivationVerifier(
        new BloomProbeGenerator,
        activationThrowingDriver($failure),
    ))->verify(
        FilterName::fromString('products.sku'),
        FilterVersion::fromInt(1),
        activationVerificationLayout(),
        [NormalizedValue::fromBytes('ABC-001')],
    ))->toThrow(BloomStorageCorrupt::class, 'corrupt bitmap metadata');
});

it('does not implicitly normalize raw application values', function (): void {
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(1);
    $layout = activationVerificationLayout();

    expect(fn () => (new ActivationVerifier(
        new BloomProbeGenerator,
        new MemoryBloomDriver,
    ))->verify(
        $name,
        $version,
        $layout,
        ['raw-value'],
    ))->toThrow(TypeError::class);
});

it('accepts normalized values only and exposes no sampling control', function (): void {
    $method = new ReflectionMethod(ActivationVerifier::class, 'verify');

    expect(array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $method->getParameters(),
    ))->toBe([
        'name',
        'version',
        'layout',
        'authoritativePresent',
    ]);
});
