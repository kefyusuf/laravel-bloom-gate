<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;

it('integrates deterministic core probes with memory driver storage semantics', function (): void {
    $layout = BloomLayout::create(1024, 7, ProbeAlgorithm::Sha256DoubleHashV1);
    $generator = new BloomProbeGenerator;
    $driver = new MemoryBloomDriver;
    $name = FilterName::fromString('products.sku');
    $version = FilterVersion::fromInt(1);

    $present = $generator->generate(
        NormalizedValue::fromBytes('ABC-001'),
        $layout,
    );

    $absent = $generator->generate(
        NormalizedValue::fromBytes('XYZ-999'),
        $layout,
    );

    expect(array_diff($absent->values(), $present->values()))->not->toBe([]);

    $driver->provision($name, $version, $layout);
    $driver->add($name, $version, $present);

    expect($driver->mightContain($name, $version, $present))->toBeTrue();
    expect($driver->mightContain($name, $version, $absent))->toBeFalse();
});
