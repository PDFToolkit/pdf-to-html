<?php

declare(strict_types=1);

namespace PDF\Html\Html;

final readonly class Text implements HtmlNode
{
    public function __construct(public string $value)
    {
    }
}
