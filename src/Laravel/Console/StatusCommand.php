<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Kefyusuf\BloomGate\Application\CoordinationStatus;
use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Application\CoordinationStatusState;
use Kefyusuf\BloomGate\Application\ManagedFilterStatus;
use Kefyusuf\BloomGate\Application\ManagedFilterStatusReader;
use Kefyusuf\BloomGate\Application\ManagedGenerationStatus;
use Kefyusuf\BloomGate\Core\FilterName;
use Throwable;

final class StatusCommand extends Command
{
    protected $signature = 'bloom:status {filter? : Bloom Gate filter name} {--leases : Include active acquired/prepared writer lease diagnostics}';

    protected $description = 'Show Bloom Gate filter lifecycle and semantic-binding status.';

    public function handle(): int
    {
        try {
            $filters = $this->requestedFilters();

            if ($filters === []) {
                $this->line('No Bloom Gate filters configured.');

                return self::SUCCESS;
            }

            foreach ($filters as $name) {
                try {
                    $status = app(ManagedFilterStatusReader::class)->read($name, includeLeases: (bool) $this->option('leases'));
                } catch (Throwable $failure) {
                    try {
                        $coordination = app(CoordinationStatusReader::class)->read($name, includeLeases: (bool) $this->option('leases'));
                    } catch (Throwable) {
                        $coordination = new CoordinationStatus(CoordinationStatusState::Unavailable, null,
                            issue: 'diagnostics_unavailable');
                    }
                    $this->line('filter='.$name->value());
                    $this->renderCoordination($coordination);

                    throw $failure;
                }
                $this->renderStatus($status);
            }

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return list<FilterName>
     */
    private function requestedFilters(): array
    {
        $filter = $this->argument('filter');

        if ($filter !== null) {
            return [FilterName::fromString($filter)];
        }

        $configured = app(Repository::class)->get('bloom-gate.filters');

        if (! is_array($configured)) {
            throw new InvalidArgumentException(
                'Bloom Gate filters configuration must be an array.',
            );
        }

        $names = [];

        foreach (array_keys($configured) as $name) {
            if (! is_string($name)) {
                throw new InvalidArgumentException(
                    'Bloom Gate configured filter names must be strings.',
                );
            }

            $names[] = FilterName::fromString($name);
        }

        return $names;
    }

    private function renderStatus(ManagedFilterStatus $status): void
    {
        $consistency = $status->consistency();

        $this->line(sprintf(
            'filter=%s registered=%s enabled=%s global-enabled=%s consistency=%s',
            $status->name()->value(),
            $this->boolean($status->registered()),
            $this->nullableBoolean($status->queryOptimizationEnabled()),
            $this->boolean($status->globalQueryOptimizationEnabled()),
            $consistency === null ? 'n/a' : $consistency->name,
        ));

        $rendered = false;

        foreach ([$status->active(), $status->candidate()] as $generation) {
            if ($generation === null) {
                continue;
            }

            $rendered = true;
            $this->renderGeneration($generation);
        }

        if (! $rendered) {
            $this->line('state=uninitialized');
        }
        if ($status->coordination() !== null) {
            $this->renderCoordination($status->coordination());
        }
    }

    private function renderCoordination(CoordinationStatus $status): void
    {
        $label = match ($status->state()) {
            CoordinationStatusState::Unadopted => 'UNADOPTED',
            CoordinationStatusState::AdoptionPending => 'ADOPTION_PENDING',
            CoordinationStatusState::Adopted => 'ADOPTED',
            CoordinationStatusState::Invalid => 'INVALID',
            CoordinationStatusState::Unavailable => 'UNAVAILABLE',
        };
        $sync = $status->synchronization();
        $targets = [];
        foreach ($sync?->currentTargets()->versions() ?? [] as $version) {
            $targets[] = (string) $version->value();
        }
        $this->line(sprintf('coordination=%s revision=%s phase=%s epoch=%s targets=[%s] candidate=%s draining-epoch=%s draining-writers=%s issue=%s',
            $label, $sync?->revision()->value() ?? 'n/a', $sync?->phase()->name ?? 'n/a',
            $sync?->currentEpoch()->value() ?? 'n/a', implode(',', $targets),
            $sync?->candidateVersion()?->value() ?? 'n/a', $sync?->drainingEpoch()?->value() ?? 'n/a',
            $status->drainingActiveWriterCount() ?? 'n/a', $status->issue() ?? 'none'));
        foreach ($status->leases() as $lease) {
            $versions = [];
            foreach ($lease->targets()->versions() as $version) {
                $versions[] = (string) $version->value();
            }
            $this->line(sprintf('lease=%s state=%s epoch=%d targets=[%s]', $lease->token()->value(),
                $lease->state()->name, $lease->epoch()->value(), implode(',', $versions)));
        }
    }

    private function renderGeneration(ManagedGenerationStatus $status): void
    {
        $layout = $status->layout();

        $this->line(sprintf(
            '%s v%d lifecycle=%s health=%s bits=%s hashes=%s algorithm=%s semantic-bound=%s semantic-match=%s',
            $status->slot(),
            $status->version()->value(),
            $status->lifecycle()->name,
            $status->health()->name,
            $layout === null ? 'n/a' : (string) $layout->bitCount(),
            $layout === null ? 'n/a' : (string) $layout->hashCount(),
            $layout?->probeAlgorithm()->name ?? 'n/a',
            $this->boolean($status->semanticBound()),
            $this->nullableBoolean($status->semanticMatches()),
        ));
    }

    private function boolean(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function nullableBoolean(?bool $value): string
    {
        return $value === null ? 'n/a' : $this->boolean($value);
    }
}
