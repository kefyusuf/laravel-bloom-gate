<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Kefyusuf\BloomGate\Application\CoordinatedLeaseRecovery;
use Kefyusuf\BloomGate\Core\AuthoritativeOutcome;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Throwable;

final class LeaseResolveCommand extends Command
{
    protected $signature = 'bloom:lease:resolve {filter : Bloom Gate filter name} {token : Durable writer lease token} {--outcome= : Known authoritative outcome: committed or aborted}';

    protected $description = 'Resolve a writer lease from a known authoritative outcome.';

    public function handle(): int
    {
        try {
            $outcome = match ($this->option('outcome')) {
                'committed' => AuthoritativeOutcome::Committed,
                'aborted' => AuthoritativeOutcome::RolledBack,
                default => throw new InvalidArgumentException('Outcome must be committed or aborted; unknown outcomes cannot resolve leases.'),
            };
            $name = FilterName::fromString($this->argument('filter'));
            $token = WriterLeaseToken::fromString($this->argument('token'));
            $result = app(CoordinatedLeaseRecovery::class)->resolve($name, $token, $outcome);
            $this->line('filter='.$name->value().' token='.$token->value().' resolution='.$result->name);

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }
}
