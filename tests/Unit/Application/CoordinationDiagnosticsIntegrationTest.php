<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Unit\Application;

require_once __DIR__.'/../../Support/Application/Task9BuildFixtures.php';
require_once __DIR__.'/../../Support/Application/Task18ProductionSafetyFixtures.php';

use Kefyusuf\BloomGate\Application\CoordinatedFilterAdopter;
use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Application\CoordinationStatusState;
use Kefyusuf\BloomGate\Application\ManagedFilterStatusReader;
use Kefyusuf\BloomGate\Application\ProductionSafetyDoctor;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\ProductionFilterInspector;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\ProductionFilterRuntimeStatus;
use Kefyusuf\BloomGate\Core\ProductionSafetyCheckStatus;
use Kefyusuf\BloomGate\Core\ProductionSafetySettings;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryCoordinationDomain;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryWriterSynchronizationStore;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18HealthyRedisDiagnostics;
use Kefyusuf\BloomGate\Tests\Support\Application\Task18StaticProductionSafetyConfiguration;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9BuildEventLog;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9FilterDefinition;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9RecordingNormalizer;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StaticFilterRegistry;
use Kefyusuf\BloomGate\Tests\Support\Application\Task9StreamingAuthoritativeSet;
use PHPUnit\Framework\TestCase;

final class CoordinationDiagnosticsIntegrationTest extends TestCase
{
    public function test_managed_status_exposes_coordination_without_creating_control_state(): void
    {
        $name = FilterName::fromString('users.email');
        $domain = new MemoryCoordinationDomain;
        $lifecycle = new MemoryCoordinatedLifecycleStore($domain);
        (new CoordinatedFilterAdopter($lifecycle))->adopt($name);
        $driver = new MemoryBloomDriver;
        $reader = new ManagedFilterStatusReader(
            $this->registry($name), new MemoryFilterControlStore($domain), $driver,
            new MemoryGenerationContractStore($driver), new SemanticFingerprintCalculator,
            coordination: $this->coordination($domain, true),
        );

        $status = $reader->read($name);
        self::assertSame(CoordinationStatusState::Adopted, $status->coordination()?->state());
        self::assertNull($status->active());
        self::assertNull($status->candidate());
        self::assertNull($lifecycle->read($name)->control());
    }

    public function test_doctor_reports_config_disagreement_pending_and_healthy_adoption_read_only(): void
    {
        $name = FilterName::fromString('users.email');
        $domain = new MemoryCoordinationDomain;
        $lifecycle = new MemoryCoordinatedLifecycleStore($domain);
        $doctor = $this->doctor($name, $this->coordination($domain, true));
        self::assertSame(ProductionSafetyCheckStatus::Fail, $doctor->inspect()->status('filter.users.email.coordination'));
        $lifecycle->claimOwnership($name, null);
        self::assertSame(ProductionSafetyCheckStatus::Warn, $doctor->inspect()->status('filter.users.email.coordination'));
        self::assertNull($lifecycle->read($name)->synchronization());
        (new CoordinatedFilterAdopter($lifecycle))->adopt($name);
        $before = $lifecycle->read($name);
        self::assertSame(ProductionSafetyCheckStatus::Pass, $doctor->inspect()->status('filter.users.email.coordination'));
        self::assertSame($before->synchronization(), $lifecycle->read($name)->synchronization());
        self::assertNull($lifecycle->read($name)->control());
        self::assertSame(ProductionSafetyCheckStatus::Fail,
            $this->doctor($name, $this->coordination($domain, false))->inspect()->status('filter.users.email.coordination'));
    }

    private function coordination(MemoryCoordinationDomain $domain, bool $required): CoordinationStatusReader
    {
        $requirement = $this->createMock(RuntimeCoordinationRequirement::class);
        $requirement->method('requiresCoordinatedV1')->willReturn($required);

        return new CoordinationStatusReader(new MemoryCoordinatedLifecycleStore($domain), new MemoryWriterSynchronizationStore($domain), $requirement);
    }

    private function registry(FilterName $name): FilterRegistry
    {
        $events = new Task9BuildEventLog;
        $definition = new Task9FilterDefinition($events, new Task9RecordingNormalizer($events), new Task9StreamingAuthoritativeSet($events, []));

        return new Task9StaticFilterRegistry(new RegisteredFilter($name, $definition, true, 100, 0.01));
    }

    private function doctor(FilterName $name, CoordinationStatusReader $coordination): ProductionSafetyDoctor
    {
        $filters = $this->createMock(ProductionFilterInspector::class);
        $filters->method('inspect')->willReturn(ProductionFilterRuntimeStatus::withoutActiveGeneration());

        return new ProductionSafetyDoctor(
            new Task18StaticProductionSafetyConfiguration(new ProductionSafetySettings('memory', null, 'lbg', [$name])),
            $this->registry($name), $filters, new Task18HealthyRedisDiagnostics, coordination: $coordination,
        );
    }
}
