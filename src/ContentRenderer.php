<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\ImageContent;

/** An application-supplied rendering engine. The library launches no programs. */
interface ContentRenderer
{
    public function render(GraphicContent|ContentGroup $content): ?ImageContent;
}
