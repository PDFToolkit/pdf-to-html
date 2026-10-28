<?php

declare(strict_types=1);

namespace PDF\Html\Attributes;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\Html\Element;

/** Preserve MathML Core layout when adapting the generic Layout mapping in Deriving HTML 1.2, Table 4. */
final readonly class MathMlLayout
{
    public function __construct(private DerivationLimits $limits)
    {
    }

    public function adapt(DerivedAttributes $attributes, bool $root): DerivedAttributes
    {
        $html = $attributes->html;
        $css = $attributes->css;
        if ($attributes->layoutDisplay) {
            unset($css['display']);
        }
        if ($root && $attributes->layoutDisplay && $attributes->placement === 'block') {
            $html['display'] ??= 'block';
        }
        return new DerivedAttributes($html, $css, $attributes->textPosition, $attributes->listNumbering, $attributes->printField, $attributes->noteType, $attributes->diagnostics, $attributes->tableHeaders);
    }

    public function classSelector(string $selector): string
    {
        return $selector . ':where(:not([data-pdf-math-layout]))';
    }

    public function protectClassStyles(Element $root): void
    {
        $nodes = 0;
        $this->protect($root, false, 0, $nodes);
    }

    private function protect(Element $element, bool $math, int $depth, int &$nodes): void
    {
        if (++$nodes > $this->limits->maximumNodes * 8 || $depth > $this->limits->maximumDepth * 2) {
            throw new DerivationException('The MathML layout output tree limit was exceeded.');
        }
        $math = $element->tag === 'math' || $math;
        if ($math && isset($element->attributes['class'])) {
            $element->attributes['data-pdf-math-layout'] = '';
        }
        foreach ($element->children as $child) {
            if (!$child instanceof Element) {
                continue;
            }
            $childMath = $math;
            if ($element->tag === 'annotation-xml' && in_array(strtolower($element->attributes['encoding'] ?? ''), ['text/html', 'application/xhtml+xml'], true)
                || in_array($element->tag, ['mi', 'mo', 'mn', 'ms', 'mtext'], true) && !in_array($child->tag, ['mglyph', 'malignmark'], true)) {
                $childMath = false;
            }
            $this->protect($child, $childMath, $depth + 1, $nodes);
        }
    }
}
