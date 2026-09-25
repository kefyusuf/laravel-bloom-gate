<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use Kefyusuf\BloomGate\Application\ManagedFilterActivator;
use Kefyusuf\BloomGate\Core\FilterName;
use LogicException;
use Throwable;

final class ActivateCommand extends Command
{
    protected $signature = 'bloom:activate
                            {filter : Registered Bloom Gate filter name}
                            {--quiescent : Acknowledge a quiescent membership-entry window}';

    protected $description = 'Freshly verify and activate the current Bloom Gate candidate.';

    public function handle(): int
    {
        try {
            $name = $this->filterName();
            $state = app(ManagedFilterActivator::class)->activate(
                $name,
                quiescent: (bool) $this->option('quiescent'),
            );
            $active = $state->activeVersion();

            if ($active === null) {
                throw new LogicException(
                    'Managed activation completed without an active generation.',
                );
            }

            $this->line(sprintf(
                'filter=%s active=v%d',
                $name->value(),
                $active->value(),
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
