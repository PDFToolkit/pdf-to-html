<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\ReadPage;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

/** Immutable mapping from default page user space to physical PDF points. */
final readonly class PageTransform
{
    public ContentMatrix $matrix;

    public function __construct(ReadPage $page)
    {
        $unitValue = $page->dictionary->get('UserUnit');
        $unitValue = $unitValue === null ? null : $page->document->resolve($unitValue);
        if ($unitValue !== null && !$unitValue instanceof NullType && !$unitValue instanceof NumericType) {
            throw new ReadingException('Page UserUnit must be a positive finite number.');
        }
        $unit = $unitValue instanceof NumericType ? (float) $unitValue->value : 1.0;
        if (!is_finite($unit) || $unit <= 0) {
            throw new ReadingException('Page UserUnit must be a positive finite number.');
        }
        $this->matrix = match ($page->attributes->normalizedRotation()) {
            0 => new ContentMatrix($unit, 0, 0, $unit),
            90 => new ContentMatrix(0, -$unit, $unit, 0),
            180 => new ContentMatrix(-$unit, 0, 0, -$unit),
            270 => new ContentMatrix(0, $unit, -$unit, 0),
            default => throw new ReadingException('Page rotation is invalid.'),
        };
    }
}
