<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class AuthorizedProbeResult
{
    private function __construct(
        private Membership $membership,
        private ?BypassReason $bypassReason,
    ) {}

    public static function definitelyAbsent(): self
    {
        return new self(Membership::DefinitelyAbsent, null);
    }

    public static function maybePresent(): self
    {
        return new self(Membership::MaybePresent, null);
    }

    public static function bypassed(BypassReason $reason): self
    {
        return new self(Membership::Bypassed, $reason);
    }

    public function membership(): Membership
    {
        return $this->membership;
    }

    public function bypassReason(): ?BypassReason
    {
        return $this->bypassReason;
    }
}
