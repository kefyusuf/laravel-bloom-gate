<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Core\ProductionSafetyCheckStatus;

final readonly class ProductionSafetyCheck
{
    public function __construct(
        private string $code,
        private ProductionSafetyCheckStatus $status,
        private string $message,
    ) {
        if (
            preg_match('/\A[a-z0-9](?:[a-z0-9._-]{0,190}[a-z0-9])?\z/', $this->code) !== 1
        ) {
            throw new InvalidArgumentException(
                'Production safety check code is invalid.',
            );
        }

        if ($this->message === '') {
            throw new InvalidArgumentException(
                'Production safety check message cannot be empty.',
            );
        }
    }

    public function code(): string
    {
        return $this->code;
    }

    public function status(): ProductionSafetyCheckStatus
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }
}
