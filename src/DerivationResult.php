<?php

declare(strict_types=1);

namespace PDF\Html;

final readonly class DerivationResult
{
    /**
     * @param list<Asset> $assets
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(public string $html, public string $css, public array $assets, public array $diagnostics)
    {
    }
}
