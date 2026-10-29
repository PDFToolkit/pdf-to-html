<?php

declare(strict_types=1);

namespace PDF\Html\Attributes;

final readonly class DerivedAttributes
{
    /**
     * @param array<string,string> $html
     * @param array<string,string> $css
     * @param list<string> $htmlStyleProperties Properties projected from HTML style, retaining their ordered source in html.
     * @param list<string> $diagnostics
     * @param list<string>|null $tableHeaders Source PDF IDs; null when an explicit HTML owner supplied headers.
     */
    public function __construct(public array $html = [], public array $css = [], public ?string $textPosition = null, public ?string $listNumbering = null, public bool $printField = false, public ?string $noteType = null, public array $diagnostics = [], public ?array $tableHeaders = null, public bool $layoutDisplay = false, public ?string $placement = null, public array $htmlStyleProperties = [])
    {
    }
    /** @return array<string, string> CSS-origin defaults/overrides, excluding the ordered HTML style projection. */
    public function cssOverrides(): array
    {
        return array_diff_key($this->css, array_flip($this->htmlStyleProperties));
    }
}
