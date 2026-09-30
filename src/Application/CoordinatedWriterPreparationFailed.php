<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use RuntimeException;
use Throwable;

final class CoordinatedWriterPreparationFailed extends RuntimeException
{
    public function __construct(
        private readonly FilterName $filterName,
        private readonly WriterLeaseToken $token,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function filterName(): FilterName
    {
        return $this->filterName;
    }

    public function token(): WriterLeaseToken
    {
        return $this->token;
    }

    public static function because(
        FilterName $filterName,
        WriterLeaseToken $token,
        string $message,
        ?Throwable $previous = null,
    ): self {
        return new self(
            filterName: $filterName,
            token: $token,
            message: $message,
            previous: $previous,
        );
    }
}
