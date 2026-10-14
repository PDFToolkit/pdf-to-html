<?php

declare(strict_types=1);

namespace PDF\Html\Html;

final class Element implements HtmlNode
{
    /**
     * @param array<string, string> $attributes
     * @param list<HtmlNode> $children
     */
    public function __construct(public string $tag, public array $attributes = [], public array $children = [])
    {
    }
}
