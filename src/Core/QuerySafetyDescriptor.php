<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

final readonly class QuerySafetyDescriptor
{
    public function __construct(
        private FilterName $filterName,
        private FilterStateRevision $revision,
        private FilterVersion $activeVersion,
        private BloomLayout $layout,
        private GenerationSemanticContract $semanticContract,
    ) {}

    public function filterName(): FilterName
    {
        return $this->filterName;
    }

    public function revision(): FilterStateRevision
    {
        return $this->revision;
    }

    public function activeVersion(): FilterVersion
    {
        return $this->activeVersion;
    }

    public function layout(): BloomLayout
    {
        return $this->layout;
    }

    public function semanticContract(): GenerationSemanticContract
    {
        return $this->semanticContract;
    }
}
