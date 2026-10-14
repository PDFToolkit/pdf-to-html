<?php

declare(strict_types=1);

namespace PDF\Html;

/** Active content is a deliberate trust decision, never enabled by a PDF. */
final readonly class DerivationPolicy
{
    public function __construct(
        public bool $allowActiveContent = false,
        public bool $allowRemoteResources = false,
        public bool $allowHtmlNamespace = true,
        public bool $includeGraphics = true,
        public bool $preferVisualFigures = false,
    ) {
    }
}
