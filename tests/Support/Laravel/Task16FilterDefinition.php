<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Laravel;

use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;

final class Task16Normalizer implements ValueNormalizer
{
    public int $calls = 0;

    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString('task16-normalizer@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        $this->calls++;

        return NormalizedValue::fromBytes('task16:'.(string) $value);
    }
}

final class Task16AuthoritativeSet implements AuthoritativeSet
{
    public int $existsCalls = 0;

    public function __construct(
        public bool $exists,
    ) {}

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString('task16-authoritative@1');
    }

    public function exists(NormalizedValue $value): bool
    {
        $this->existsCalls++;

        return $this->exists;
    }

    public function values(): iterable
    {
        return [];
    }
}

final class Task16FilterDefinition implements FilterDefinition
{
    public Task16Normalizer $normalizer;

    public Task16AuthoritativeSet $authoritativeSet;

    public function __construct(bool $exists)
    {
        $this->normalizer = new Task16Normalizer;
        $this->authoritativeSet = new Task16AuthoritativeSet($exists);
    }

    public function normalizer(): ValueNormalizer
    {
        return $this->normalizer;
    }

    public function authoritativeSet(): AuthoritativeSet
    {
        return $this->authoritativeSet;
    }

    public function consistency(): ConsistencyContract
    {
        return ConsistencyContract::ImmutableV1;
    }
}
