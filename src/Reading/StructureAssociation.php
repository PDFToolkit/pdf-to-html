<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Syntax\Object\DictionaryType;

/** One forward relationship to check against the inverse ParentTree. */
final readonly class StructureAssociation
{
    public function __construct(public DictionaryType $container, public DictionaryType $element, public ?int $mcid = null)
    {
        if ($mcid !== null && $mcid < 0) {
            throw new \InvalidArgumentException('A structure MCID cannot be negative.');
        }
    }
}
