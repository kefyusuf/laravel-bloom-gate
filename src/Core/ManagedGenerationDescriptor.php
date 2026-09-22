<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class ManagedGenerationDescriptor
{
    public function __construct(
        private BloomLayout $layout,
        private GenerationSemanticContract $semanticContract,
    ) {}

    public function layout(): BloomLayout
    {
        return $this->layout;
    }

    public function semanticContract(): GenerationSemanticContract
    {
        return $this->semanticContract;
    }
}
