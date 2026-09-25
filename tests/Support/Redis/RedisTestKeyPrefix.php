<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

final class RedisTestKeyPrefix
{
    public static function unique(string $prefix): string
    {
        /** @var string $bytes */
        $bytes = random_bytes(8);

        return $prefix.bin2hex($bytes);
    }
}
