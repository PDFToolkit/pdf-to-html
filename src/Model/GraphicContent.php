<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class GraphicContent implements SourceNode
{
    public function __construct(public string $kind, public string $instructions, public float $widthPoints = 0, public float $heightPoints = 0, public ?int $page = null)
    {
    }
}
