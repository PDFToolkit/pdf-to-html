<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

use PDF\Html\DerivationException;

/** ISO 32000-2 Table 191; Deriving HTML 1.2, physical page 61. */
enum HighlightingMode: string
{
    case None = 'N';
    case Invert = 'I';
    case Outline = 'O';
    case Push = 'P';
    case Toggle = 'T';

    public static function fromPdf(?string $value): self
    {
        return self::tryFrom($value ?? 'I') ?? throw new DerivationException('Widget highlighting must be N, I, O, P or T.');
    }

    public function usesDownAppearance(): bool
    {
        return $this === self::Push || $this === self::Toggle;
    }
}
