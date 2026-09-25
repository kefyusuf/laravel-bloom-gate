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

final class Task15Normalizer implements ValueNormalizer
{
    public int $calls = 0;

    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString('task15-normalizer@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        $this->calls++;

        return NormalizedValue::fromBytes('task15:'.(string) $value);
    }
}

final class Task15AuthoritativeSet implements AuthoritativeSet
{
    public int $existsCalls = 0;

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString('task15-authoritative@1');
    }

    public function exists(NormalizedValue $value): bool
    {
        $this->existsCalls++;

        return true;
    }

    public function values(): iterable
    {
        return [];
    }
}

final readonly class Task15FilterDefinition implements FilterDefinition
{
    public function __construct(
        public Task15Normalizer $normalizer = new Task15Normalizer,
        public Task15AuthoritativeSet $authoritativeSet = new Task15AuthoritativeSet,
    ) {}

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
        return ConsistencyContract::PreAddV1;
    }
}
