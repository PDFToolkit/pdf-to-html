<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Html\Model\GraphicViewport;

/** ISO 32000-2 appearance mapping: fit the transformed Form BBox to the annotation Rect. */
final readonly class AppearancePlacement
{
    public ContentMatrix $beforeFormMatrix;

    /**
     * @param array{int|float, int|float, int|float, int|float} $box
     * @param array{int|float, int|float, int|float, int|float} $rectangle
     */
    public function __construct(array $box, ContentMatrix $formMatrix, array $rectangle)
    {
        $bounds = (new GraphicViewport(min($box[0], $box[2]), min($box[1], $box[3]), max($box[0], $box[2]), max($box[1], $box[3])))->transformed($formMatrix);
        $left = min($rectangle[0], $rectangle[2]);
        $bottom = min($rectangle[1], $rectangle[3]);
        $width = abs($rectangle[2] - $rectangle[0]);
        $height = abs($rectangle[3] - $rectangle[1]);
        $x = $width / ($bounds->right - $bounds->left);
        $y = $height / ($bounds->top - $bounds->bottom);
        $this->beforeFormMatrix = new ContentMatrix($x, 0, 0, $y, $left - $x * $bounds->left, $bottom - $y * $bounds->bottom);
    }
}
