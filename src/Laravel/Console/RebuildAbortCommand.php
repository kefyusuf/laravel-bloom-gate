<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Kefyusuf\BloomGate\Application\OnlineRebuildCoordinator;
use Kefyusuf\BloomGate\Application\RebuildProgress;
use Kefyusuf\BloomGate\Core\FilterName;

final class RebuildAbortCommand extends RebuildCommand
{
    protected $signature = 'bloom:rebuild:abort {filter : Registered Bloom Gate filter name} {--wait=0 : Maximum seconds to poll a blocked abort}';

    protected $description = 'Start or resume durable coordinated rebuild abort.';

    protected function advance(OnlineRebuildCoordinator $coordinator, FilterName $name): RebuildProgress
    {
        return $coordinator->abort($name);
    }
}
