<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel;

use Kefyusuf\BloomGate\Application\ExistenceResult;
use Kefyusuf\BloomGate\Application\MembershipAdder;
use Kefyusuf\BloomGate\Application\QueryGate;

final readonly class BloomGateManager
{
    public function __construct(
        private QueryGate $queries,
        private MembershipAdder $writes,
    ) {}

    public function exists(
        string $filter,
        string|int $value,
    ): bool {
        return $this->queries->exists($filter, $value);
    }

    public function existsResult(
        string $filter,
        string|int $value,
    ): ExistenceResult {
        return $this->queries->existsResult($filter, $value);
    }

    public function add(
        string $filter,
        string|int $value,
    ): void {
        $this->writes->add($filter, $value);
    }

    /**
     * @param  iterable<string|int>  $values
     */
    public function addMany(
        string $filter,
        iterable $values,
    ): void {
        $this->writes->addMany($filter, $values);
    }
}
