<?php

declare(strict_types=1);

namespace PDF\Html\Model;

use PDF\Html\Reading\ContentMatrix;

/** A finite source-space viewport; transformation returns a new physical viewport. */
final readonly class GraphicViewport
{
    public function __construct(public float $left, public float $bottom, public float $right, public float $top)
    {
        foreach ([$left, $bottom, $right, $top] as $coordinate) {
            if (!is_finite($coordinate)) {
                throw new \InvalidArgumentException('A graphics viewport requires finite coordinates.');
            }
        }
        if ($right <= $left || $top <= $bottom) {
            throw new \InvalidArgumentException('A graphics viewport requires positive dimensions.');
        }
    }

    public function transformed(ContentMatrix $matrix): self
    {
        $xs = $ys = [];
        foreach ([[$this->left, $this->bottom], [$this->right, $this->bottom], [$this->right, $this->top], [$this->left, $this->top]] as [$x, $y]) {
            $xs[] = $matrix->a * $x + $matrix->c * $y + $matrix->e;
            $ys[] = $matrix->b * $x + $matrix->d * $y + $matrix->f;
        }
        return new self(min($xs), min($ys), max($xs), max($ys));
    }
}
