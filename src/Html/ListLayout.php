<?php

declare(strict_types=1);

namespace PDF\Html\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;

/** Section 4.3.5.4, physical page 28: compensate generated label/body wrappers. */
final class ListLayout
{
    private int $nodes = 0;
    private int $adapted = 0;

    public function __construct(private readonly DerivationLimits $limits)
    {
    }

    public function adapt(Element $element, int $depth = 0): void
    {
        if (++$this->nodes > $this->limits->maximumNodes * 8 || $depth > $this->limits->maximumDepth * 2) {
            throw new DerivationException('List layout exceeds the output tree budget.');
        }
        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                $this->adapt($child, $depth + 1);
            }
        }
        if ($element->tag !== 'li' || ($element->attributes['data-pdf-se-type'] ?? '') !== 'LI') {
            return;
        }
        $children = array_values(array_filter($element->children, static fn (HtmlNode $child): bool => !$child instanceof Text || trim($child->value) !== ''));
        if (count($children) !== 2 || !$children[0] instanceof Element || !$children[1] instanceof Element
            || ($children[0]->attributes['data-pdf-se-type'] ?? '') !== 'Lbl'
            || ($children[1]->attributes['data-pdf-se-type'] ?? '') !== 'LBody') {
            return;
        }
        $body = $children[1];
        $first = $this->firstBlock($body);
        if ($first === null) {
            return;
        }
        $element->attributes['data-pdf-list-layout'] = '';
        $children[0]->attributes['data-pdf-list-marker'] = '';
        $first->attributes['data-pdf-list-first'] = '';
        $labelFirst = $this->firstBlock($children[0]);
        if ($labelFirst !== null) {
            $labelFirst->attributes['data-pdf-list-first'] = '';
        }
        ++$this->adapted;
    }

    /** The already bounded output tree has no cycles; follow only its first block chain. */
    private function firstBlock(Element $element): ?Element
    {
        $first = null;
        while (true) {
            $next = null;
            foreach ($element->children as $child) {
                if ($child instanceof Text && trim($child->value) !== '') {
                    return $first;
                }
                if ($child instanceof Element) {
                    $next = $child;
                    break;
                }
            }
            if ($next === null || !in_array($next->tag, ['div', 'p', 'section', 'article', 'blockquote', 'ul', 'ol', 'dl', 'table', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                return $first;
            }
            $first = $element = $next;
        }
    }

    public function css(): string
    {
        if ($this->adapted === 0) {
            return '';
        }
        // Zero specificity: PDF CSS attributes/classes and imported author styles win.
        return ':where([data-pdf-list-layout]){display:grid;grid-template-columns:max-content minmax(0,1fr);align-items:start}'
            .':where([data-pdf-list-marker]){margin-inline-end:.5em}'
            .':where([data-pdf-list-first]){margin-block-start:0}';
    }

    public function count(): int
    {
        return $this->adapted;
    }
}
