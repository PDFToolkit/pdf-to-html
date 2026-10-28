<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

use PDF\Html\Html\Element;

/** Trusted, bounded CSS for pointer interaction; author CSS retains its priority. */
final readonly class WidgetHighlightStyle
{
    private string $token;

    public function __construct(private HighlightingMode $mode, string $identifier)
    {
        // Do not interpolate PDF names or author-controlled HTML identifiers in selectors.
        $this->token = hash('sha256', $identifier);
    }

    /** @param array<string, string> $declarations
     * @return array<string, string> */
    public function apply(Element $field, array $declarations): array
    {
        $field->attributes['data-pdf-highlight'] = $this->mode->value;
        $field->attributes['data-pdf-widget-style'] = $this->token;
        if ($this->mode === HighlightingMode::Outline) {
            $declarations['--pdf-widget-border-color'] = $declarations['border-color'] ?? 'currentColor';
            $declarations['border-color'] = 'var(--pdf-widget-active-border-color, var(--pdf-widget-border-color))';
        }
        return $declarations;
    }

    public function css(bool $downAppearance = false, bool $rolloverAppearance = false): string
    {
        $selector = '[data-pdf-widget-style="' . $this->token . '"]';
        // :where keeps widget styling below Layout/HTML/CSS/NSO author styles.
        // Native button themes can otherwise add an unrelated pressed background for H=N.
        $css = ':where(button' . $selector . '){appearance:none;background-color:ButtonFace;border:2px outset ButtonBorder}' . "\n";
        // Native checkbox/radio themes ignore CSS border painting. Keep their
        // actual input semantics and focus behavior, but paint their states in CSS.
        $check = 'input' . $selector . '[type="checkbox"]';
        $radio = 'input' . $selector . '[type="radio"]';
        $css .= ':where(' . $check . ',' . $radio . '){appearance:none;box-sizing:border-box;width:1em;height:1em;border:2px solid currentColor;padding:2px;vertical-align:middle;background-clip:content-box}' . "\n";
        $css .= ':where(' . $radio . '){border-radius:50%}' . "\n";
        $css .= ':where(' . $check . ':checked,' . $radio . ':checked){background-image:linear-gradient(currentColor,currentColor)}' . "\n";
        $css .= ':where(' . $check . ':indeterminate){background-image:linear-gradient(currentColor,currentColor);background-size:100% 25%;background-repeat:no-repeat;background-position:center}' . "\n";
        $active = match ($this->mode) {
            HighlightingMode::None => 'filter:none;translate:none',
            HighlightingMode::Invert => 'filter:invert(1)',
            HighlightingMode::Outline => '--pdf-widget-active-border-color:rgb(from var(--pdf-widget-border-color) calc(255 - r) calc(255 - g) calc(255 - b))',
            HighlightingMode::Push, HighlightingMode::Toggle => $downAppearance ? 'translate:none' : 'translate:1px 1px',
        };
        $css .= ':where(' . $selector . ':active){' . $active . "}\n";
        if ($downAppearance || $rolloverAppearance) {
            $wrapper = $selector . '>[data-pdf-widget-appearances]';
            $css .= ':where(' . $wrapper . '){display:inline-grid}' . "\n";
            $css .= ':where(' . $wrapper . '>[data-pdf-widget-appearance]){grid-area:1 / 1}' . "\n";
            $normal = '[data-pdf-widget-appearance="normal"]';
            $down = '[data-pdf-widget-appearance="down"]';
            $rollover = '[data-pdf-widget-appearance="rollover"]';
            // Keep the normal caption in the accessibility tree, including while pressed.
            $css .= ':where(' . $wrapper . '>' . $down . ',' . $wrapper . '>' . $rollover . '){opacity:0;pointer-events:none}' . "\n";
            if ($rolloverAppearance) {
                $css .= ':where(' . $selector . ':hover:not(:active)>[data-pdf-widget-appearances]>' . $normal . '){opacity:0}' . "\n";
                $css .= ':where(' . $selector . ':hover:not(:active)>[data-pdf-widget-appearances]>' . $rollover . '){opacity:1}' . "\n";
            }
            if ($downAppearance) {
                $css .= ':where(' . $selector . ':active>[data-pdf-widget-appearances]>' . $normal . '){opacity:0}' . "\n";
                $css .= ':where(' . $selector . ':active>[data-pdf-widget-appearances]>' . $down . '){opacity:1}' . "\n";
            }
        }
        return $css;
    }
}
