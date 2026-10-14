<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class StructureProperties
{
    /**
     * @param list<string> $classes
     * @param list<string> $references
     */
    public function __construct(public ?string $id = null, public ?string $language = null, public ?string $actualText = null, public ?string $alternateDescription = null, public ?string $expansion = null, public array $classes = [], public array $references = [])
    {
    }
}
