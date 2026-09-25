<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use Kefyusuf\BloomGate\Application\CandidateDiscarder;
use Kefyusuf\BloomGate\Core\FilterName;
use Throwable;

final class DiscardCommand extends Command
{
    protected $signature = 'bloom:discard {filter : Registered Bloom Gate filter name}';

    protected $description = 'Retire and clear the current Bloom Gate candidate generation.';

    public function handle(): int
    {
        try {
            $name = $this->filterName();
            $state = app(CandidateDiscarder::class)->discard($name);
            $active = $state->activeVersion();

            $this->line(sprintf(
                'filter=%s candidate=discarded active=%s',
                $name->value(),
                $active === null ? 'none' : 'v'.$active->value(),
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
