<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\NormalizedValue;

final readonly class ActivationVerifier
{
    public function __construct(
        private BloomProbeGenerator $probeGenerator,
        private BloomDriver $driver,
    ) {}

    /**
     * @param iterable<NormalizedValue> $authoritativePresent
     */
    public function verify(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
        iterable $authoritativePresent,
    ): ActivationVerificationResult {
        $checkedCount = 0;

        foreach ($authoritativePresent as $value) {
            $positions = $this->probeGenerator->generate($value, $layout);
            $checkedCount++;

            if ($this->driver->mightContain($name, $version, $positions) === false) {
                return new ActivationVerificationResult(
                    filterName: $name,
                    filterVersion: $version,
                    status: ActivationVerificationStatus::FalseNegativeDetected,
                    checkedCount: $checkedCount,
                );
            }
        }

        return new ActivationVerificationResult(
            filterName: $name,
            filterVersion: $version,
            status: ActivationVerificationStatus::Passed,
            checkedCount: $checkedCount,
        );
    }
}
