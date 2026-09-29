<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Application;

use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use RuntimeException;

final class AuthoritativeOutcomeUncertain extends RuntimeException
{
    private function __construct(
        private readonly FilterName $filterName,
        private readonly WriterLeaseToken $token,
    ) {
        parent::__construct(
            'Authoritative membership outcome is unknown; the coordinated writer lease remains active.',
        );
    }

    public static function forPrepared(
        PreparedCoordinatedWrite $prepared,
    ): self {
        return new self(
            filterName: $prepared->filterName(),
            token: $prepared->token(),
        );
    }

    public function filterName(): FilterName
    {
        return $this->filterName;
    }

    public function token(): WriterLeaseToken
    {
        return $this->token;
    }
}
