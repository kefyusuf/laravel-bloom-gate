<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Kefyusuf\BloomGate\Application\ManagedFilterVerifier;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationStatus;
use Throwable;

final class VerifyCommand extends Command
{
    protected $signature = 'bloom:verify {filter : Registered Bloom Gate filter name}';

    protected $description = 'Verify the current Bloom Gate candidate generation.';

    public function __construct(
        private readonly ManagedFilterVerifier $verifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $name = $this->filterName();
            $result = $this->verifier->verify($name);

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
        $filter = $this->argument('filter');

        if (! is_string($filter)) {
            throw new InvalidArgumentException(
                'Bloom Gate filter argument must be a string.',
            );
        }

        return FilterName::fromString($filter);
    }
}
