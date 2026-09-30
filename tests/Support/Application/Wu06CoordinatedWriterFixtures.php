<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Application;

use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomDriverOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Contracts\Exception\WriterLeaseReleased;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\SynchronizationTargetSet;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use LogicException;
use Throwable;

final class Wu06EventLog
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}

final class Wu06Normalizer implements ValueNormalizer
{
    public function __construct(
        private Wu06EventLog $events,
        private string $identity = 'wu06-normalizer@1',
    ) {}

    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString($this->identity);
    }

    public function normalize(string|int $value): NormalizedValue
    {
        $this->events->add('normalize:'.(string) $value);

        return NormalizedValue::fromBytes('normalized:'.(string) $value);
    }
}

final readonly class Wu06AuthoritativeSet implements AuthoritativeSet
{
    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString('wu06-authoritative@1');
    }

    public function exists(NormalizedValue $value): bool
    {
        throw new LogicException('WU-06 writer preparation does not query authoritative membership.');
    }

    public function values(): iterable
    {
        return [];
    }
}

final readonly class Wu06FilterDefinition implements FilterDefinition
{
    public function __construct(
        private ValueNormalizer $normalizer,
        private AuthoritativeSet $authoritativeSet,
        private ConsistencyContract $consistency = ConsistencyContract::PreAddV1,
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
        return $this->consistency;
    }
}

final readonly class Wu06FilterRegistry implements FilterRegistry
{
    public function __construct(
        private RegisteredFilter $registered,
    ) {}

    public function globalQueryOptimizationEnabled(): bool
    {
        return true;
    }

    public function get(FilterName $name): RegisteredFilter
    {
        if ($name->equals($this->registered->name()) === false) {
            throw new LogicException('Unexpected WU-06 filter lookup.');
        }

        return $this->registered;
    }
}

final class Wu06GenerationContractStore implements GenerationContractStore
{
    /** @var array<int, ManagedGenerationDescriptor> */
    private array $descriptors = [];

    public function __construct(
        private Wu06EventLog $events,
    ) {}

    public function put(
        FilterVersion $version,
        ManagedGenerationDescriptor $descriptor,
    ): void {
        $this->descriptors[$version->value()] = $descriptor;
    }

    public function read(
        FilterName $name,
        FilterVersion $version,
    ): ?ManagedGenerationDescriptor {
        $this->events->add('contract:'.$version->value());

        return $this->descriptors[$version->value()] ?? null;
    }

    public function bind(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $expectedLayout,
        GenerationSemanticContract $semanticContract,
    ): void {
        throw new LogicException('WU-06 writer preparation never binds generation contracts.');
    }
}

final class Wu06BloomDriver implements BulkBloomDriver
{
    public int $addManyCalls = 0;

    /** @var list<int> */
    public array $versions = [];

    public ?int $failVersion = null;

    public function __construct(
        private Wu06EventLog $events,
    ) {}

    public function provision(
        FilterName $name,
        FilterVersion $version,
        BloomLayout $layout,
    ): void {
        throw new LogicException('WU-06 writer preparation never provisions generations.');
    }

    public function add(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): void {
        throw new LogicException('WU-06 writer preparation uses addMany.');
    }

    public function addMany(
        FilterName $name,
        FilterVersion $version,
        array $items,
    ): void {
        $this->addManyCalls++;
        $this->versions[] = $version->value();
        $this->events->add('bloom:'.$version->value());

        if ($this->failVersion === $version->value()) {
            throw new BloomDriverOperationFailed(
                'WU-06 simulated target Bloom write failure.',
            );
        }
    }

    public function mightContain(
        FilterName $name,
        FilterVersion $version,
        BitPositions $positions,
    ): bool {
        throw new LogicException('WU-06 writer preparation never probes membership.');
    }

    public function destroy(
        FilterName $name,
        FilterVersion $version,
    ): void {
        throw new LogicException('WU-06 writer preparation never destroys generations.');
    }
}

final class Wu06WriterSynchronizationStore implements WriterSynchronizationStore
{
    public int $acquireCalls = 0;

    public int $markPreparedCalls = 0;

    public int $releaseCalls = 0;

    public int $countIncrements = 0;

    public ?Throwable $acquireFailure = null;

    public ?Throwable $markPreparedFailure = null;

    public ?Throwable $releaseFailure = null;

    public bool $markPreparedPersistsBeforeFailure = false;

    public bool $markPreparedReturnsAcquired = false;

    private SynchronizationEpoch $currentEpoch;

    private SynchronizationTargetSet $currentTargets;

    /** @var array<string, WriterLease> */
    private array $leases = [];

    /** @var array<int, int> */
    private array $counts = [];

    public function __construct(
        private Wu06EventLog $events,
        SynchronizationEpoch $epoch,
        SynchronizationTargetSet $targets,
    )
    {
        $this->currentEpoch = $epoch;
        $this->currentTargets = $targets;
    }

    public function read(FilterName $name): ?SynchronizationState
    {
        return null;
    }

    public function acquire(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        $this->acquireCalls++;
        $this->events->add('store:acquire:'.$token->value());

        if ($this->acquireFailure !== null) {
            throw $this->acquireFailure;
        }

        $existing = $this->leases[$token->value()] ?? null;

        if ($existing !== null) {
            if ($existing->state() === WriterLeaseState::Released) {
                throw new WriterLeaseReleased('WU-06 token is terminal.');
            }

            return $existing;
        }

        $lease = new WriterLease(
            token: $token,
            state: WriterLeaseState::Acquired,
            epoch: $this->currentEpoch,
            targets: $this->currentTargets,
        );
        $this->leases[$token->value()] = $lease;
        $epoch = $lease->epoch()->value();
        $this->counts[$epoch] = ($this->counts[$epoch] ?? 0) + 1;
        $this->countIncrements++;

        return $lease;
    }

    public function markPrepared(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        $this->markPreparedCalls++;
        $this->events->add('store:prepare');

        $lease = $this->leases[$token->value()] ?? null;

        if ($lease === null) {
            throw new UnknownWriterLease('WU-06 token is unknown.');
        }

        if ($lease->state() === WriterLeaseState::Released) {
            throw new WriterLeaseReleased('WU-06 token is terminal.');
        }

        if ($this->markPreparedReturnsAcquired) {
            return $lease;
        }

        if ($this->markPreparedPersistsBeforeFailure) {
            $lease = $this->prepared($lease);
            $this->leases[$token->value()] = $lease;
        }

        if ($this->markPreparedFailure !== null) {
            throw $this->markPreparedFailure;
        }

        if ($lease->state() === WriterLeaseState::Prepared) {
            return $lease;
        }

        $lease = $this->prepared($lease);
        $this->leases[$token->value()] = $lease;

        return $lease;
    }

    public function release(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        $this->releaseCalls++;
        $this->events->add('store:release');

        if ($this->releaseFailure !== null) {
            throw $this->releaseFailure;
        }

        $lease = $this->leases[$token->value()] ?? null;

        if ($lease === null) {
            throw new UnknownWriterLease('WU-06 token is unknown.');
        }

        if ($lease->state() === WriterLeaseState::Released) {
            return $lease;
        }

        $epoch = $lease->epoch()->value();
        $this->counts[$epoch] = max(0, ($this->counts[$epoch] ?? 0) - 1);

        $released = new WriterLease(
            token: $lease->token(),
            state: WriterLeaseState::Released,
            epoch: $lease->epoch(),
            targets: $lease->targets(),
        );
        $this->leases[$token->value()] = $released;

        return $released;
    }

    public function activeWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): int {
        return $this->counts[$epoch->value()] ?? 0;
    }

    public function rotate(
        SynchronizationEpoch $epoch,
        SynchronizationTargetSet $targets,
    ): void {
        $this->currentEpoch = $epoch;
        $this->currentTargets = $targets;
    }

    public function lease(WriterLeaseToken $token): ?WriterLease
    {
        return $this->leases[$token->value()] ?? null;
    }

    private function prepared(WriterLease $lease): WriterLease
    {
        return new WriterLease(
            token: $lease->token(),
            state: WriterLeaseState::Prepared,
            epoch: $lease->epoch(),
            targets: $lease->targets(),
        );
    }
}
