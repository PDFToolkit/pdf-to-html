<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Model\TaggedDocument;
use PDF\Html\Forms\AppearanceRenderer;

/** A reusable configuration; each derivation owns a fresh session and budgets. */
final readonly class HtmlDeriver
{
    public function __construct(
        private DerivationPolicy $policy = new DerivationPolicy(),
        private DerivationLimits $limits = new DerivationLimits(),
        private ?ContentRenderer $renderer = null,
        private ?AppearanceRenderer $appearanceRenderer = null,
    ) {
    }

    public function derive(TaggedDocument $document): DerivationResult
    {
        return (new DerivationSession($document, $this->policy, $this->limits, $this->renderer, $this->appearanceRenderer))->derive();
    }
}
