<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use LogicException;
use RuntimeException;

final class Task9BuildEventLog
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}

final class Task9RecordingControlStore implements FilterControlStore
{
    private MemoryFilterControlStore $inner;

    /** @var list<FilterControlState> */
    private array $writes = [];

    public function __construct(?FilterControlState $initial = null)
    {
        $this->inner = new MemoryFilterControlStore;

        if ($initial !== null) {
            if ($initial->revision()->value() !== 1) {
                throw new InvalidArgumentException(
                    'Task 9 recording control store seed must use revision 1.',
                );
            }

            $this->inner->compareAndSwap(
                $initial->filterName(),
                $initial,
                null,
            );
        }
    }

    public function read(FilterName $name): ?FilterControlState
    {
        return $this->inner->read($name);
    }

    public function compareAndSwap(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void {
        $this->inner->compareAndSwap(
            $name,
            $next,
            $expectedRevision,
        );

        $this->writes[] = $next;
    }

    /** @return list<FilterControlState> */
    public function writes(): array
    {
        return $this->writes;
    }
}

final class Task9RecordingNormalizer implements ValueNormalizer
{
    public int $calls = 0;

    public function __construct(
        private Task9BuildEventLog $events,
        private string|int|null $throwOn = null,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString('task9-normalizer@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        $this->calls++;
        $this->events->add('normalize:'.(string) $value);

        if ($value === $this->throwOn) {
            throw new RuntimeException('Task 9 normalizer failure.');
        }

        return NormalizedValue::fromBytes('normalized:'.(string) $value);
    }
}

final class Task9StreamingAuthoritativeSet implements AuthoritativeSet
{
    /**
     * @param  list<string|int>  $values
     */
    public function __construct(
        private Task9BuildEventLog $events,
        private array $values,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString('task9-set@1');
    }

    public function exists(NormalizedValue $value): bool
    {
        return false;
    }

    public function values(): iterable
    {
        $this->events->add('values:start');

        foreach ($this->values as $index => $value) {
            $this->events->add('values:yield:'.$index);

            yield $value;
        }
    }
}

final class Task9FilterDefinition implements FilterDefinition
{
    public int $normalizerResolutions = 0;

    public int $authoritativeSetResolutions = 0;

    public function __construct(
        private Task9BuildEventLog $events,
        private ValueNormalizer $normalizer,
        private AuthoritativeSet $authoritativeSet,
        private ConsistencyContract $consistency = ConsistencyContract::PreAddV1,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function normalizer(): ValueNormalizer
    {
        $this->normalizerResolutions++;
        $this->events->add('definition:normalizer');

        return $this->normalizer;
    }

    public function authoritativeSet(): AuthoritativeSet
    {
        $this->authoritativeSetResolutions++;
        $this->events->add('definition:authoritative-set');

        return $this->authoritativeSet;
    }

    public function consistency(): ConsistencyContract
    {
        $this->events->add('definition:consistency');

        return $this->consistency;
    }
}

final class Task9StaticFilterRegistry implements FilterRegistry
{
    public int $getCalls = 0;

    public function __construct(
        private RegisteredFilter $filter,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function globalQueryOptimizationEnabled(): bool
    {
        return true;
    }

    public function get(FilterName $name): RegisteredFilter
    {
        $this->getCalls++;

        if ($name->equals($this->filter->name()) === false) {
            throw new LogicException('Unexpected Task 9 filter lookup.');
        }

        return $this->filter;
    }
}

final class Task9RecordingBulkBloomDriver implements BulkBloomDriver
{
    public ?BloomLayout $provisionedLayout = null;

    public ?FilterVersion $provisionedVersion = null;

    /** @var list<list<BitPositions>> */
    public array $batches = [];

    private int $batchCalls = 0;

    public function __construct(
        private Task9BuildEventLog $events,
        private FilterControlStore $controlStore,
        private ?int $failOnBatch = null,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function provision(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
    ): void {
        $state = $this->controlStore->read($name);

        if ($state === null) {
            throw new RuntimeException('Expected control state before provision.');
        }

        $lifecycle = null;

        foreach ($state->generations() as $generation) {
            if ($generation->version()->equals($version)) {
                $lifecycle = $generation->lifecycle()->name;
                break;
            }
        }

        $this->events->add('provision:'.($lifecycle ?? 'missing'));
        $this->provisionedLayout = $layout;
        $this->provisionedVersion = $version;
    }

    public function add(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): void {
        throw new LogicException('Task 9 managed build must use addMany.');
    }

    public function addMany(
        FilterName $name,
        FilterVersion $version,
        array $items,
    ): void {
        $this->batchCalls++;
        $this->events->add('bulk:'.count($items));

        if ($this->failOnBatch === $this->batchCalls) {
            throw new BloomDriverOperationFailed(
                'Task 9 simulated bulk write failure.',
            );
        }

        $this->batches[] = $items;
    }

    public function mightContain(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): bool {
        throw new LogicException('Task 9 builder must not query membership.');
    }

    public function destroy(
        FilterName $name,
        FilterVersion $version,
    ): void {
        throw new LogicException('Task 9 builder must not destroy generations.');
    }
}

final class Task9RecordingGenerationContractStore implements GenerationContractStore
{
    public int $bindCalls = 0;

    public ?BloomLayout $boundLayout = null;

    public ?GenerationSemanticContract $boundContract = null;

    public ?FilterVersion $boundVersion = null;

    public function __construct(
        private Task9BuildEventLog $events,
        private FilterControlStore $controlStore,
    ) {
        // Explicit test-fixture constructor body.
    }

    public function read(
        FilterName $name,
        FilterVersion $version,
    ): ?ManagedGenerationDescriptor {
        return null;
    }

    public function bind(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $expectedLayout,
        GenerationSemanticContract $semanticContract,
    ): void {
        $state = $this->controlStore->read($name);

        if ($state === null) {
            throw new RuntimeException('Expected control state before semantic binding.');
        }

        $lifecycle = null;

        foreach ($state->generations() as $generation) {
            if ($generation->version()->equals($version)) {
                $lifecycle = $generation->lifecycle()->name;
                break;
            }
        }

        $this->events->add('bind:'.($lifecycle ?? 'missing'));
        $this->bindCalls++;
        $this->boundLayout = $expectedLayout;
        $this->boundContract = $semanticContract;
        $this->boundVersion = $version;
    }
}
