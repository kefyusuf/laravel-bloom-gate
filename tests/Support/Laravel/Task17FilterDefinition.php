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
use RuntimeException;

final class Task17Normalizer implements ValueNormalizer
{
    public int $calls = 0;

    public function __construct(
        public string $semanticIdentity = 'task17-normalizer@1',
    ) {
        // Explicit test-fixture constructor body.
    }

    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString($this->semanticIdentity);
    }

    public function normalize(string|int $value): NormalizedValue
    {
        $this->calls++;

        return NormalizedValue::fromBytes('task17:'.(string) $value);
    }
}

final class Task17AuthoritativeSet implements AuthoritativeSet
{
    public int $existsCalls = 0;

    public ?int $throwAfter = null;

    /**
     * @param list<string|int> $values
     */
    public function __construct(
        public array $values,
        public string $semanticIdentity = 'task17-authoritative@1',
    ) {
        // Explicit test-fixture constructor body.
    }

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString($this->semanticIdentity);
    }

    public function exists(NormalizedValue $value): bool
    {
        $this->existsCalls++;

        foreach ($this->values as $candidate) {
            if ($value->bytes() === 'task17:'.(string) $candidate) {
                return true;
            }
        }

        return false;
    }

    public function values(): iterable
    {
        $yielded = 0;

        foreach ($this->values as $value) {
            if ($this->throwAfter !== null && $yielded >= $this->throwAfter) {
                throw new RuntimeException('Task 17 authoritative traversal failure.');
            }

            $yielded++;

            yield $value;
        }
    }
}

final class Task17FilterDefinition implements FilterDefinition
{
    public Task17Normalizer $normalizer;

    public Task17AuthoritativeSet $authoritativeSet;

    /**
     * @param list<string|int> $values
     */
    public function __construct(
        array $values,
        public ConsistencyContract $consistencyContract = ConsistencyContract::ImmutableV1,
    ) {
        $this->normalizer = new Task17Normalizer;
        $this->authoritativeSet = new Task17AuthoritativeSet($values);
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
        return $this->consistencyContract;
    }
}
