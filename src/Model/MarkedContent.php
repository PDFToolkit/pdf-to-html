<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class MarkedContent implements SourceNode
{
    public StructureProperties $properties;
    /**
     * @param list<SourceNode> $children
     */
    public function __construct(public array $children, ?StructureProperties $properties = null, public ?int $page = null, public bool $artifact = false, public ?GraphicProgram $source = null)
    {
        $this->properties = $properties ?? new StructureProperties();
    }
}
