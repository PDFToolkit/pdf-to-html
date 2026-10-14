<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

/** An isolated, in-memory PDF. The recipient chooses its rendering engine and any I/O. */
final readonly class RenderingDocument
{
    public function __construct(public string $bytes, public float $widthPoints, public float $heightPoints, public int $sourcePage)
    {
        if ($bytes === '' || !is_finite($widthPoints) || !is_finite($heightPoints) || $widthPoints <= 0 || $heightPoints <= 0 || $sourcePage < 1) {
            throw new \InvalidArgumentException('A rendering document needs bytes and a finite, positive viewport.');
        }
    }
}
