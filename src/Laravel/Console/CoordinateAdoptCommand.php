<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use Kefyusuf\BloomGate\Application\AdoptionHandoff;
use Kefyusuf\BloomGate\Application\CoordinatedFilterAdopter;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Core\FilterName;
use Throwable;

final class CoordinateAdoptCommand extends Command
{
    protected $signature = 'bloom:coordinate:adopt {filter : Registered Bloom Gate filter name} {--quiescent : Acknowledge completion of the brownfield writer handoff}';

    protected $description = 'Adopt a Bloom Gate filter into durable coordinated lifecycle ownership.';

    public function handle(): int
    {
        try {
            $name = FilterName::fromString($this->argument('filter'));
            app(FilterRegistry::class)->get($name);
            $result = app(CoordinatedFilterAdopter::class)->adopt($name,
                $this->option('quiescent') ? AdoptionHandoff::Quiescent : AdoptionHandoff::NotAsserted);
            $this->line('filter='.$name->value().' adoption='.$result->name);

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }
}
