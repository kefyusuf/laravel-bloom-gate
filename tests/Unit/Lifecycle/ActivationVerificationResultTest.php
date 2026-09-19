<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationResult;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;

it('defines only conclusive activation verification statuses', function (): void {
    expect(array_map(
        static fn (ActivationVerificationStatus $status): string => $status->name,
        ActivationVerificationStatus::cases(),
    ))->toBe([
        'Passed',
        'FalseNegativeDetected',
    ]);
});

it('binds evidence to filter name and version without exposing the failed raw value', function (): void {
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(7);
    $layout = BloomLayout::create(64, 3, ProbeAlgorithm::Sha256DoubleHashV1);
    $driver = new MemoryBloomDriver;
    $driver->provision($name, $version, $layout);

    $result = (new ActivationVerifier(
        new BloomProbeGenerator,
        $driver,
    ))->verify(
        $name,
        $version,
        $layout,
        [NormalizedValue::fromBytes('secret-value-that-must-not-escape')],
    );

    expect($result)->toBeInstanceOf(ActivationVerificationResult::class)
        ->and($result->status())->toBe(ActivationVerificationStatus::FalseNegativeDetected)
        ->and($result->filterName())->toBe($name)
        ->and($result->filterVersion())->toBe($version)
        ->and(get_object_vars($result))->toBe([])
        ->and(method_exists($result, 'failedValue'))->toBeFalse()
        ->and(method_exists($result, 'value'))->toBeFalse()
        ->and(method_exists($result, 'bytes'))->toBeFalse();
});
