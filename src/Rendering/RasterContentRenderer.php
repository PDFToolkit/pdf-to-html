<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\Html\ContentRenderer;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\ImageContent;

/** Section 4.4 grouped rendering: the explicitly supplied engine paints a complete isolated PDF. */
final readonly class RasterContentRenderer implements ContentRenderer
{
    public function __construct(private GraphicsRasterizer $rasterizer, private GraphicPdfExporter $exporter = new GraphicPdfExporter())
    {
    }

    public function render(GraphicContent|ContentGroup $content): ImageContent
    {
        $document = $this->exporter->export($content);
        $image = $this->rasterizer->rasterize($document);
        return new ImageContent($image->bytes, $image->mediaType, $document->widthPoints, $document->heightPoints, $document->sourcePage);
    }
}
