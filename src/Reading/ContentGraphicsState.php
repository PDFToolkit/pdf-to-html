<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Syntax\Object\DictionaryType;

final readonly class ContentGraphicsState
{
    public function __construct(public ?DictionaryType $font, public ContentMatrix $matrix)
    {
    }
}
