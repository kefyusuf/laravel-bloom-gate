<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use Kefyusuf\BloomGate\Application\ProductionSafetyDoctor;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'bloom:doctor';

    protected $description = 'Inspect Bloom Gate production-safety prerequisites without mutating state.';

    public function handle(): int
    {
        try {
            $report = app(ProductionSafetyDoctor::class)->inspect();

            foreach ($report->checks() as $check) {
                $this->line(sprintf(
                    '%s %s %s',
                    $check->status()->label(),
                    $check->code(),
                    $check->message(),
                ));
            }

            return $report->hasFailures()
                ? self::FAILURE
                : self::SUCCESS;
        } catch (Throwable) {
            $this->error(
                'Bloom Gate doctor failed because diagnostics could not be evaluated safely.',
            );

            return self::FAILURE;
        }
    }
}
