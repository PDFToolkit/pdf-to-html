<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\Html\Model\ImageContent;

/** Explicit application boundary: a renderer receives an isolated PDF, never PDF scripts. */
interface GraphicsRasterizer
{
    public function rasterize(RenderingDocument $document): ImageContent;
}
