<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class ContentGroup implements SourceNode
{
    /**
     * @param list<SourceNode> $children
     */
    public function __construct(public array $children, public ?int $page = null, public ?GraphicProgram $source = null, public ?GraphicViewport $viewport = null)
    {
    }
}
