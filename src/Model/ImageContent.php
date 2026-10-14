<?php

declare(strict_types=1);

namespace PDF\Html\Model;

final readonly class ImageContent implements SourceNode
{
    public function __construct(public ?string $bytes, public string $mediaType, public float $widthPoints, public float $heightPoints, public ?int $page = null, public ?GraphicProgram $source = null)
    {
    }
}
