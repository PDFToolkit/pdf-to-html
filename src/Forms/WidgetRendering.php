<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

use PDF\Html\Html\Element;
use PDF\Html\Html\HtmlNode;

final readonly class WidgetRendering
{
    /**
     * @param list<HtmlNode> $nodes
     * @param list<string> $diagnostics
     */
    public function __construct(public array $nodes, public Element $field, public array $diagnostics = [], public string $css = '')
    {
    }
}
