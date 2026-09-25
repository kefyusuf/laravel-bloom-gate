<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Kefyusuf\BloomGate\Application\ManagedFilterStatus;
use Kefyusuf\BloomGate\Application\ManagedFilterStatusReader;
use Kefyusuf\BloomGate\Application\ManagedGenerationStatus;
use Kefyusuf\BloomGate\Core\FilterName;
use Throwable;

final class StatusCommand extends Command
{
    protected $signature = 'bloom:status {filter? : Bloom Gate filter name}';

    protected $description = 'Show Bloom Gate filter lifecycle and semantic-binding status.';

    public function __construct(
        private readonly ManagedFilterStatusReader $statuses,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $filters = $this->requestedFilters();

            if ($filters === []) {
                $this->line('No Bloom Gate filters configured.');

                return self::SUCCESS;
            }

            foreach ($filters as $name) {
                $this->renderStatus($this->statuses->read($name));
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
            if (! is_string($filter)) {
                throw new InvalidArgumentException(
                    'Bloom Gate filter argument must be a string.',
                );
            }

            return [FilterName::fromString($filter)];
        }

        $configured = $this->config->get('bloom-gate.filters');

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
        $this->line(sprintf(
            'filter=%s registered=%s enabled=%s global-enabled=%s consistency=%s',
            $status->name()->value(),
            $this->boolean($status->registered()),
            $this->nullableBoolean($status->queryOptimizationEnabled()),
            $this->boolean($status->globalQueryOptimizationEnabled()),
            $status->consistency()?->name ?? 'n/a',
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
