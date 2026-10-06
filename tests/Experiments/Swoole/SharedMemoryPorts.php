<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use Closure;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\BloomLayoutMismatch;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Core\ActiveGenerationSnapshot;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use LogicException;

final readonly class SharedSnapshots implements ActiveGenerationSnapshotReader
{
    public function __construct(private SharedMemoryDomain $domain) {}

    public function readActive(FilterName $name): ?ActiveGenerationSnapshot
    {
        $state = $this->domain->state();
        if (! $this->domain->name->equals($name) || $state === null) {
            return null;
        }

        return new ActiveGenerationSnapshot($name, FilterStateRevision::fromInt($state['revision']),
            FilterVersion::fromInt($state['version']), LifecycleState::Active, HealthState::Healthy);
    }
}

final readonly class SharedContracts implements GenerationContractStore
{
    public function __construct(private SharedMemoryDomain $domain) {}

    public function read(FilterName $name, FilterVersion $version): ?ManagedGenerationDescriptor
    {
        return $this->domain->name->equals($name) ? $this->domain->managed($version->value()) : null;
    }

    public function bind(FilterName $name, FilterVersion $version, BloomLayout $expectedLayout, GenerationSemanticContract $semanticContract): void
    {
        throw new LogicException('HTTP consumers cannot bind immutable contracts.');
    }
}

final readonly class SharedBloom implements BloomDriver
{
    public function __construct(private SharedMemoryDomain $domain) {}

    public function mightContain(FilterName $name, FilterVersion $version, BitPositions $positions): bool
    {
        if (! $this->domain->name->equals($name)) {
            throw new BloomStorageCorrupt('Wrong incarnation.');
        }
        if (! $this->domain->managed($version->value())->layout()->equals($positions->layout())) {
            throw new BloomLayoutMismatch('Wrong immutable layout.');
        }

        return $this->domain->contains($version->value(), $positions);
    }

    public function provision(FilterName $name, FilterVersion $version, BloomLayout $layout): void
    {
        throw new LogicException('Only parent publication can provision storage.');
    }

    public function add(FilterName $name, FilterVersion $version, BitPositions $positions): void
    {
        throw new LogicException('Sealed generation mutation is forbidden.');
    }

    public function destroy(FilterName $name, FilterVersion $version): void
    {
        throw new LogicException('Generation retirement before parent shutdown is forbidden.');
    }
}

final class SharedProbe implements AuthorizedProbe
{
    public ?Closure $afterAuthorization = null;

    public function __construct(private readonly SharedMemoryDomain $domain) {}

    public function probe(QuerySafetyDescriptor $descriptor, BitPositions $positions): AuthorizedProbeResult
    {
        if (! $positions->layout()->equals($descriptor->layout())) {
            throw new BloomLayoutMismatch('Probe layout mismatch.');
        }
        $before = $this->domain->state();
        if ($before === null || ! $descriptor->filterName()->equals($this->domain->name)
            || $before['revision'] !== $descriptor->revision()->value() || $before['version'] !== $descriptor->activeVersion()->value()) {
            return AuthorizedProbeResult::bypassed(BypassReason::controlStateChanged());
        }
        try {
            $managed = $this->domain->managed($before['version']);
            if ($this->domain->manifestDigest($before['version']) !== $before['manifest']
                || ! $managed->layout()->equals($descriptor->layout()) || ! $managed->semanticContract()->equals($descriptor->semanticContract())) {
                return AuthorizedProbeResult::bypassed(BypassReason::generationStorageCorrupt());
            }
            ($this->afterAuthorization ?? static function (): void {})();
            $present = $this->domain->contains($before['version'], $positions);
            if ($before !== $this->domain->state()
                || $this->domain->manifestDigest($before['version']) !== $before['manifest']) {
                return AuthorizedProbeResult::bypassed(BypassReason::controlStateChanged());
            }

            return $present ? AuthorizedProbeResult::maybePresent() : AuthorizedProbeResult::definitelyAbsent();
        } catch (BloomStorageCorrupt) {
            return AuthorizedProbeResult::bypassed(BypassReason::generationStorageCorrupt());
        }
    }
}
