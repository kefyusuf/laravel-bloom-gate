<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\ProductionSafetyCheckStatus;

final readonly class ProductionSafetyReport
{
    /**
     * @var list<ProductionSafetyCheck>
     */
    private array $checks;

    /**
     * @param  list<ProductionSafetyCheck>  $checks
     */
    public function __construct(array $checks)
    {
        $byCode = [];

        foreach ($checks as $check) {
            if (isset($byCode[$check->code()])) {
                throw new InvalidArgumentException(
                    'Production safety check codes must be unique.',
                );
            }

            $byCode[$check->code()] = true;
        }

        $this->checks = array_values($checks);
    }

    /**
     * @return list<ProductionSafetyCheck>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function status(string $code): ProductionSafetyCheckStatus
    {
        foreach ($this->checks as $check) {
            if ($check->code() === $code) {
                return $check->status();
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown production safety check [%s].',
            $code,
        ));
    }

    public function hasFailures(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status() === ProductionSafetyCheckStatus::Fail) {
                return true;
            }
        }

        return false;
    }
}
