<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;

final readonly class QuerySafetyDescriptorResolution
{
    private function __construct(
        private ?QuerySafetyDescriptor $descriptor,
        private ?BypassReason $bypassReason,
    ) {}

    public static function ready(QuerySafetyDescriptor $descriptor): self
    {
        return new self($descriptor, null);
    }

    public static function bypassed(BypassReason $reason): self
    {
        return new self(null, $reason);
    }

    public function descriptor(): ?QuerySafetyDescriptor
    {
        return $this->descriptor;
    }

    public function bypassReason(): ?BypassReason
    {
        return $this->bypassReason;
    }
}
