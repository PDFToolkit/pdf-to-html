<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Html\HtmlNode;

final readonly class AssociationPlan
{
    /**
     * @param list<HtmlNode> $nodes
     * @param list<HtmlNode> $after
     */
    public function __construct(public array $nodes = [], public array $after = [], public bool $alternative = false, public bool $supplement = false)
    {
    }
}
