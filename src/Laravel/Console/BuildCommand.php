<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use Kefyusuf\BloomGate\Application\ManagedFilterBuilder;
use Kefyusuf\BloomGate\Core\FilterName;
use LogicException;
use Throwable;

final class BuildCommand extends Command
{
    protected $signature = 'bloom:build {filter : Registered Bloom Gate filter name}';

    protected $description = 'Build a new Bloom Gate candidate generation.';

    public function handle(): int
    {
        try {
            $name = $this->filterName();
            $result = app(ManagedFilterBuilder::class)->buildResult($name);
            $candidate = $result->state()->candidateVersion();

            if ($candidate === null) {
                throw new LogicException(
                    'Managed build completed without a candidate generation.',
                );
            }

            $layout = $result->layout();

            $this->line(sprintf(
                'filter=%s candidate=v%d bits=%d hashes=%d algorithm=%s processed=%d',
                $name->value(),
                $candidate->value(),
                $layout->bitCount(),
                $layout->hashCount(),
                $layout->probeAlgorithm()->name,
                $result->processedCount(),
            ));

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    private function filterName(): FilterName
    {
        return FilterName::fromString($this->argument('filter'));
    }
}
