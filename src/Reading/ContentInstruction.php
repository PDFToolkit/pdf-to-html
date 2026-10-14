<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Syntax\FileStructure\BasicType;

final readonly class ContentInstruction
{
    /** @param list<BasicType> $operands */
    public function __construct(public string $operator, public array $operands, public ?InlineImageData $inlineImage = null)
    {
    }
}
