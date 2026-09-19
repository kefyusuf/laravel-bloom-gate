<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Lifecycle;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;

final readonly class ActivationVerificationResult
{
    public function __construct(
        private FilterName $filterName,
        private FilterVersion $filterVersion,
        private ActivationVerificationStatus $status,
        private int $checkedCount,
    ) {
        if ($checkedCount < 0) {
            throw new InvalidArgumentException('Activation verification checked count cannot be negative.');
        }

        if (
            $status === ActivationVerificationStatus::FalseNegativeDetected
            && $checkedCount < 1
        ) {
            throw new InvalidArgumentException(
                'False-negative verification evidence must include at least one checked value.',
            );
        }
    }

    public function filterName(): FilterName
    {
        return $this->filterName;
    }

    public function filterVersion(): FilterVersion
    {
        return $this->filterVersion;
    }

    public function status(): ActivationVerificationStatus
    {
        return $this->status;
    }

    public function checkedCount(): int
    {
        return $this->checkedCount;
    }
}
