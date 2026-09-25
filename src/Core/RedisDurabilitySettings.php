<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class RedisDurabilitySettings
{
    public function __construct(
        private bool $appendOnly,
        private string $appendFsync,
        private string $maxmemoryPolicy,
    ) {
        if ($this->appendFsync === '' || $this->maxmemoryPolicy === '') {
            throw new InvalidArgumentException(
                'Redis durability diagnostics fields cannot be empty.',
            );
        }
    }

    public function appendOnly(): bool
    {
        return $this->appendOnly;
    }

    public function appendFsync(): string
    {
        return $this->appendFsync;
    }

    public function maxmemoryPolicy(): string
    {
        return $this->maxmemoryPolicy;
    }
}
