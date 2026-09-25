<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Kefyusuf\BloomGate\Application\CandidateDiscarder;
use Kefyusuf\BloomGate\Core\FilterName;
use Throwable;

final class DiscardCommand extends Command
{
    protected $signature = 'bloom:discard {filter : Registered Bloom Gate filter name}';

    protected $description = 'Retire and clear the current Bloom Gate candidate generation.';

    public function __construct(
        private readonly CandidateDiscarder $discarder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $name = $this->filterName();
            $state = $this->discarder->discard($name);
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
        $filter = $this->argument('filter');

        if (! is_string($filter)) {
            throw new InvalidArgumentException(
                'Bloom Gate filter argument must be a string.',
            );
        }

        return FilterName::fromString($filter);
    }
}
