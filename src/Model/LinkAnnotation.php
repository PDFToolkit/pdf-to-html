<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class LinkAnnotation implements SourceNode
{
    public function __construct(public ?string $uri = null, public ?string $structureTarget = null, public ?int $pageTarget = null)
    {
    }
}
