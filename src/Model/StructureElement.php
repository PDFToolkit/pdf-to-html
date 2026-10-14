<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class StructureElement implements SourceNode
{
    public StructureProperties $properties;
    /**
     * @param list<SourceNode> $children
     * @param list<AttributeObject> $attributes
     * @param list<AssociatedFile> $associatedFiles
     */
    public function __construct(public string $key, public string $type, public array $children = [], public ?string $namespace = null, ?StructureProperties $properties = null, public array $attributes = [], public array $associatedFiles = [], public ?string $namespaceIdentity = null)
    {
        $this->properties = $properties ?? new StructureProperties();
    }
}
