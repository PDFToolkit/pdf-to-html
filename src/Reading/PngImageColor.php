<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

/** The PDF-selected interpretation, separate from codec metadata. */
final readonly class PngImageColor
{
    public function __construct(public int $components, public ?string $iccProfile = null)
    {
    }
}
