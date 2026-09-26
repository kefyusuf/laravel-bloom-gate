<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\Membership;

final readonly class ExistenceResult
{
    private function __construct(
        private bool $exists,
        private Membership $membership,
        private ?BypassReason $bypassReason,
    ) {}

    public static function definitelyAbsent(): self
    {
        return new self(
            exists: false,
            membership: Membership::DefinitelyAbsent,
            bypassReason: null,
        );
    }

    public static function maybePresent(bool $exists): self
    {
        return new self(
            exists: $exists,
            membership: Membership::MaybePresent,
            bypassReason: null,
        );
    }

    public static function bypassed(
        bool $exists,
        BypassReason $reason,
    ): self {
        return new self(
            exists: $exists,
            membership: Membership::Bypassed,
            bypassReason: $reason,
        );
    }

    public function exists(): bool
    {
        return $this->exists;
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
