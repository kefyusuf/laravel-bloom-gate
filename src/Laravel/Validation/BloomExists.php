<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Kefyusuf\BloomGate\Application\QueryGate;

final readonly class BloomExists implements ValidationRule
{
    public function __construct(
        private string $filter,
    ) {}

    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! is_string($value) && ! is_int($value)) {
            $fail('The :attribute field must be a string or integer.');

            return;
        }

        if (! app(QueryGate::class)->exists($this->filter, $value)) {
            $fail('validation.exists')->translate();
        }
    }
}
