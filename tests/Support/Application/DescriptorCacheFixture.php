<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\QuerySafetyDescriptorCache;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use RuntimeException;

final class DescriptorCacheFixture implements QuerySafetyDescriptorCache
{
    public ?QuerySafetyDescriptor $descriptor = null;

    public int $gets = 0;

    public int $puts = 0;

    public int $forgets = 0;

    public ?string $failure = null;

    public function get(FilterName $name, GenerationSemanticContract $expectedContract): ?QuerySafetyDescriptor
    {
        $this->gets++;
        $this->fail('get');

        return $this->descriptor !== null
            && $this->descriptor->filterName()->equals($name)
            && $this->descriptor->semanticContract()->equals($expectedContract)
            ? $this->descriptor : null;
    }

    public function put(QuerySafetyDescriptor $descriptor): void
    {
        $this->puts++;
        $this->fail('put');
        $this->descriptor = $descriptor;
    }

    public function forget(FilterName $name): void
    {
        $this->forgets++;
        $this->fail('forget');
        $this->descriptor = null;
    }

    private function fail(string $operation): void
    {
        if ($this->failure === $operation) {
            throw new RuntimeException('Cache failure');
        }
    }
}

final class DescriptorCacheSequenceProbe implements AuthorizedProbe
{
    /** @var list<BitPositions> */
    public array $positions = [];

    /** @param list<AuthorizedProbeResult> $results */
    public function __construct(private array $results) {}

    public function probe(QuerySafetyDescriptor $descriptor, BitPositions $positions): AuthorizedProbeResult
    {
        $this->positions[] = $positions;

        return array_shift($this->results) ?? throw new RuntimeException('Unexpected extra probe');
    }
}
