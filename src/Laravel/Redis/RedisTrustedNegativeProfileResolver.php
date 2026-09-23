<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Redis;

use Illuminate\Contracts\Config\Repository;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;

final readonly class RedisTrustedNegativeProfileResolver
{
    public const string STANDALONE_PRIMARY_DURABLE_V1 = 'standalone-primary-durable-v1';

    public function __construct(
        private Repository $config,
    ) {}

    public function resolve(): ?string
    {
        $profile = $this->config->get(
            'bloom-gate.drivers.redis.trusted_negative_profile',
        );

        if ($profile === null) {
            return null;
        }

        if (
            ! is_string($profile)
            || $profile !== self::STANDALONE_PRIMARY_DURABLE_V1
        ) {
            throw new InvalidConfiguration(
                'Unsupported Bloom Gate Redis trusted-negative profile.',
            );
        }

        return $profile;
    }
}
