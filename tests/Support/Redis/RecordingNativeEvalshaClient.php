<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Redis;

final class RecordingNativeEvalshaClient extends Redis
{
    /** @var list<array{string, array<array-key, mixed>, int}> */
    public array $shaCalls = [];

    public int $evalCalls = 0;

    public int $clears = 0;

    public function __construct(
        public mixed $shaResult,
        public ?string $currentError = null,
        public ?string $lastError = null,
    ) {}

    /** @param array<array-key, mixed> $args */
    public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        $this->shaCalls[] = [$sha1, $args, $num_keys];
        $this->lastError = $this->currentError;

        return $this->shaResult;
    }

    /** @param array<array-key, mixed> $args */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->evalCalls++;

        return 42;
    }

    public function clearLastError(): bool
    {
        $this->clears++;
        $this->lastError = null;

        return true;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
