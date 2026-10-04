<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Kefyusuf\BloomGate\Application\OnlineRebuildCoordinator;
use Kefyusuf\BloomGate\Application\RebuildProgress;
use Kefyusuf\BloomGate\Core\FilterName;
use Throwable;

class RebuildCommand extends Command
{
    protected $signature = 'bloom:rebuild {filter : Registered Bloom Gate filter name} {--wait=0 : Maximum seconds to poll a blocked workflow}';

    protected $description = 'Start or resume the durable coordinated rebuild workflow.';

    public function handle(): int
    {
        try {
            $wait = $this->option('wait');
            if (! is_string($wait) || preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/', $wait) !== 1) {
                throw new InvalidArgumentException('Wait must be a non-negative integer number of seconds.');
            }
            $name = FilterName::fromString($this->argument('filter'));
            $deadline = hrtime(true) / 1_000_000_000 + (int) $wait;
            $coordinator = app(OnlineRebuildCoordinator::class);
            do {
                $progress = $this->advance($coordinator, $name);
                if ($progress === RebuildProgress::Advanced) {
                    continue;
                }
                if ($progress !== RebuildProgress::Blocked || (int) $wait === 0) {
                    break;
                }
                $remaining = $deadline - hrtime(true) / 1_000_000_000;
                if ($remaining <= 0) {
                    break;
                }
                usleep((int) min(100_000, $remaining * 1_000_000));
            } while (true);
            $this->line('filter='.$name->value().' progress='.$progress->name);

            return $progress === RebuildProgress::RecoveryRequired ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    protected function advance(OnlineRebuildCoordinator $coordinator, FilterName $name): RebuildProgress
    {
        return $coordinator->advance($name);
    }
}
