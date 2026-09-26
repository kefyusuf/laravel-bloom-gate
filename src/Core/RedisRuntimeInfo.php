<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class RedisRuntimeInfo
{
    public function __construct(
        private string $version,
        private string $mode,
        private string $role,
    ) {
        if ($this->version === '' || $this->mode === '' || $this->role === '') {
            throw new InvalidArgumentException(
                'Redis runtime diagnostics fields cannot be empty.',
            );
        }
    }

    public function version(): string
    {
        return $this->version;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function role(): string
    {
        return $this->role;
    }
}
