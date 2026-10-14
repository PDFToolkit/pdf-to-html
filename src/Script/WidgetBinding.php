<?php

declare(strict_types=1);

namespace PDF\Html\Script;

use PDF\Html\Model\Widget;

/** The precise HTML control derived from one source widget in one document. */
final readonly class WidgetBinding
{
    public function __construct(public Widget $widget, public string $htmlId)
    {
        if ($htmlId === '' || preg_match('/\s/u', $htmlId) === 1) {
            throw new \InvalidArgumentException('A widget binding requires a nonempty HTML identifier without whitespace.');
        }
    }
}
