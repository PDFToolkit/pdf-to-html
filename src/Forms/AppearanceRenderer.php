<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

use PDF\Html\Html\HtmlNode;
use PDF\Html\Model\SourceNode;

interface AppearanceRenderer
{
    /**
     * @param list<SourceNode> $appearance
     * @return list<HtmlNode>
     */
    public function render(array $appearance): array;
}
