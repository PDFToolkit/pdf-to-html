<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class TextContent implements SourceNode
{
    public function __construct(public string $text, public ?int $page = null)
    {
    }
}
