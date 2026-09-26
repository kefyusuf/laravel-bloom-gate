<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use Kefyusuf\BloomGate\Application\ManagedFilterVerifier;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Throwable;

final class VerifyCommand extends Command
{
    protected $signature = 'bloom:verify {filter : Registered Bloom Gate filter name}';

    protected $description = 'Verify the current Bloom Gate candidate generation.';

    public function handle(): int
    {
        try {
            $name = $this->filterName();
            $result = app(ManagedFilterVerifier::class)->verify($name);

            $this->line(sprintf(
                'filter=%s candidate=v%d status=%s checked=%d',
                $name->value(),
                $result->filterVersion()->value(),
                $result->status()->name,
                $result->checkedCount(),
            ));

            return $result->status() === ActivationVerificationStatus::Passed
                ? self::SUCCESS
                : self::FAILURE;
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
