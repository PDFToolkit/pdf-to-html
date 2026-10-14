<?php

declare(strict_types=1);

namespace PDF\Html\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;

/** Repairs PDF/HTML content-model differences within cumulative expansion budgets. */
final class ContentModelRepair
{
    private readonly DerivationLimits $limits;
    private int $nodes = 0;
    private int $bytes = 0;
    /** @var list<string> */
    public array $diagnostics = [];
    /** @var array<int,true> */
    private array $active = [];

    public function __construct(?DerivationLimits $limits = null)
    {
        $this->limits = $limits ?? new DerivationLimits();
    }

    /** @return list<HtmlNode> */
    public function repair(Element $element): array
    {
        $this->nodes = $this->bytes = 0;
        $this->active = [];
        $this->diagnostics = [];
        return $this->walk($element, [], 0);
    }

    /**
     * @param list<string> $ancestors
     * @return list<HtmlNode>
     */
    private function walk(Element $element, array $ancestors, int $depth): array
    {
        if ($depth > $this->limits->maximumDepth * 2 || isset($this->active[spl_object_id($element)])) {
            throw new DerivationException('The HTML repair depth limit or cycle guard was exceeded.');
        }
        $this->charge($element);
        $identity = spl_object_id($element);
        $this->active[$identity] = true;
        $element->tag = strtolower($element->tag);
        $parent = $ancestors === [] ? null : $ancestors[array_key_last($ancestors)];
        if (in_array('th', $ancestors, true)) {
            if (preg_match('/\Ah[1-6]\z/D', $element->tag) === 1) {
                $element->tag = 'p';
            } elseif (in_array($element->tag, ['article','aside','nav','section','header','footer','hgroup'], true)) {
                $element->tag = 'div';
            }
        }
        if ($element->tag === 'figcaption' && $parent !== 'figure') {
            $element->tag = 'div';
        }
        if ($element->tag === 'rb') {
            $element->tag = 'span';
            $this->diagnostics[] = 'An obsolete rb wrapper became a span for conforming current HTML.';
        }
        if (in_array($element->tag, ['rt','rp'], true) && !in_array($parent, ['ruby','rtc'], true)) {
            $element->tag = 'span';
            $this->diagnostics[] = 'An rt/rp outside ruby became a span for conforming current HTML.';
        }
        if ($element->tag === 'form' && in_array('form', $ancestors, true)) {
            $element->tag = 'div';
            $this->removeAttributes($element, ['name','action','method','enctype','novalidate','autocomplete']);
            $this->diagnostics[] = 'A nested form wrapper became a div to preserve valid HTML.';
        }
        $children = [];
        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                foreach ($this->walk($child, [...$ancestors, $element->tag], $depth + 1) as $repaired) {
                    $children[] = $repaired;
                }
            } else {
                $this->charge($child);
                $children[] = $child;
            }
        }
        unset($this->active[$identity]);
        $element->children = $children;
        if ($element->tag === 'rtc') {
            $this->diagnostics[] = 'An obsolete rtc wrapper was removed within ruby, or became a span outside ruby.';
            if ($parent === 'ruby') {
                $attributes = $element->attributes;
                unset($attributes['id']);
                foreach ($children as $child) {
                    if ($child instanceof Element && $attributes !== []) {
                        $this->chargeParts($child->tag, $attributes);
                        $child->attributes = [...$attributes, ...$child->attributes];
                    }
                }
                if (isset($element->attributes['id'])) {
                    array_unshift($children, $this->make('span', ['id' => $element->attributes['id']]));
                }
                return $children;
            }
            $element->tag = 'span';
            foreach ($children as $child) {
                if ($child instanceof Element && in_array($child->tag, ['rt','rp'], true)) {
                    $child->tag = 'span';
                }
            }
        }

        if ($element->tag === 'label' && !$this->validLabel($element)) {
            $element->tag = 'span';
            if (isset($element->attributes['for'])) {
                $element->attributes['data-pdf-original-for'] = $element->attributes['for'];
                unset($element->attributes['for']);
            }
            $this->diagnostics[] = 'An invalid outer label became a span; nested labels and controls were retained.';
        }
        if ($element->tag === 'head') {
            $seenTitle = $seenCharset = $seenViewport = false;
            $metadata = [];
            foreach ($element->children as $child) {
                if ($child instanceof Element) {
                    if ($child->tag === 'title') {
                        if ($seenTitle) {
                            continue;
                        }
                        $seenTitle = true;
                    } elseif ($child->tag === 'meta' && isset($child->attributes['charset'])) {
                        if ($seenCharset) {
                            continue;
                        }
                        $seenCharset = true;
                    } elseif ($child->tag === 'meta' && strtolower($child->attributes['name'] ?? '') === 'viewport') {
                        if ($seenViewport) {
                            continue;
                        }
                        $seenViewport = true;
                    }
                }
                $metadata[] = $child;
            }
            $element->children = $metadata;
        }
        if ($element->tag === 'figure') {
            $caption = null;
            $body = [];
            foreach ($children as $child) {
                if ($child instanceof Element && $child->tag === 'figcaption') {
                    if ($caption === null) {
                        $caption = $child;
                    } else {
                        $child->tag = 'div';
                        $body[] = $child;
                        $this->diagnostics[] = 'Only one figcaption was retained; an additional caption became a div.';
                    }
                } else {
                    $body[] = $child;
                }
            }
            if ($caption !== null) {
                // A figcaption must be first or last, never between content items.
                $element->children = [$caption, ...$body];
            }
        }
        if (in_array($element->tag, ['ul','ol'], true)) {
            $captions = $items = [];
            foreach ($element->children as $child) {
                if ($child instanceof Element && ($child->tag === 'caption' || ($child->attributes['data-pdf-se-type'] ?? '') === 'Caption')) {
                    $child->tag = 'div';
                    $captions[] = $child;
                } elseif ($child instanceof Element && in_array($child->tag, ['li','script'], true)) {
                    $items[] = $child;
                } elseif ($child instanceof Text && trim($child->value) === '') {
                    $items[] = $child;
                } else {
                    $items[] = $this->make('li', ['style' => 'list-style-type:none'], [$child]);
                }
            }
            $element->children = $items;
            return [...$captions, $element];
        }
        if ($element->tag === 'table') {
            return $this->table($element);
        }
        if (in_array($element->tag, ['thead','tbody','tfoot'], true)) {
            $rows = [];
            foreach ($element->children as $child) {
                if ($child instanceof Element && in_array($child->tag, ['tr','script'], true) || $child instanceof Text && trim($child->value) === '') {
                    $rows[] = $child;
                } elseif ($child instanceof Element && in_array($child->tag, ['th','td'], true)) {
                    $rows[] = $this->make('tr', [], [$child]);
                } else {
                    $rows[] = $this->make('tr', [], [$this->make('td', [], [$child])]);
                }
            }
            $element->children = $rows;
        } elseif ($element->tag === 'tr') {
            $cells = [];
            foreach ($element->children as $child) {
                if ($child instanceof Element && in_array($child->tag, ['th','td','script'], true) || $child instanceof Text && trim($child->value) === '') {
                    $cells[] = $child;
                } else {
                    $cells[] = $this->make('td', [], [$child]);
                }
            }
            $element->children = $cells;
        }

        $insideAnchor = in_array('a', $ancestors, true) || in_array('button', $ancestors, true);
        $phrasing = $this->phrasing($element->tag) || ($element->tag === 'a' && $this->hasPhrasingAncestor($ancestors));
        $interactiveBoundary = in_array($element->tag, ['a','button'], true) || $insideAnchor;
        return $this->split($element, $phrasing, $interactiveBoundary);
    }

    /** @return list<HtmlNode> */
    private function table(Element $element): array
    {
        $caption = null;
        $columns = $heads = $bodies = $feet = $scripts = $after = $looseRows = [];
        foreach ($element->children as $child) {
            if ($child instanceof Text && trim($child->value) === '') {
                continue;
            }
            if (!$child instanceof Element) {
                $after[] = $child;
            } elseif ($child->tag === 'caption') {
                $this->extractCaptionBlocks($child, $after);
                if ($caption === null) {
                    $caption = $child;
                } else {
                    $child->tag = 'div';
                    $after[] = $child;
                }
            } elseif ($child->tag === 'colgroup') {
                $columns[] = $child;
            } elseif ($child->tag === 'thead' && $heads === []) {
                $heads[] = $child;
            } elseif ($child->tag === 'tfoot' && $feet === []) {
                $feet[] = $child;
            } elseif (in_array($child->tag, ['tbody','thead','tfoot'], true)) {
                if ($looseRows !== []) {
                    $bodies[] = $this->make('tbody', [], $looseRows);
                    $looseRows = [];
                }
                $child->tag = 'tbody';
                $bodies[] = $child;
            } elseif ($child->tag === 'tr') {
                $looseRows[] = $child;
            } elseif ($child->tag === 'script') {
                $scripts[] = $child;
            } else {
                $after[] = $child;
            }
        }
        if ($looseRows !== []) {
            if ($bodies === []) {
                array_push($bodies, ...$looseRows);
            } else {
                $bodies[] = $this->make('tbody', [], $looseRows);
            }
        }
        $element->children = [...($caption === null ? [] : [$caption]), ...$columns, ...$heads, ...$bodies, ...$feet, ...$scripts];
        return [$element, ...$after];
    }

    /** @return list<HtmlNode> */
    private function split(Element $element, bool $phrasing, bool $interactive): array
    {
        $needsSplit = false;
        foreach ($element->children as $child) {
            if ($child instanceof Element && (($phrasing && $this->block($child->tag)) || ($interactive && $this->interactive($child)))) {
                $needsSplit = true;
                break;
            }
        }
        if (!$needsSplit) {
            return [$element];
        }
        $result = $segment = [];
        $first = true;
        foreach ($element->children as $child) {
            if ($child instanceof Element && (($phrasing && $this->block($child->tag)) || ($interactive && $this->interactive($child)))) {
                if ($segment !== []) {
                    $result[] = $this->make($element->tag, $this->segmentAttributes($element, $first), $segment);
                    $first = false;
                    $segment = [];
                } elseif ($first && isset($element->attributes['id'])) {
                    $result[] = $this->make('span', ['id' => $element->attributes['id']]);
                    $first = false;
                }
                $result[] = $child;
            } else {
                $segment[] = $child;
            }
        }
        if ($segment !== []) {
            $result[] = $this->make($element->tag, $this->segmentAttributes($element, $first), $segment);
        }
        return $result;
    }

    public function block(string $tag): bool
    {
        return in_array($tag, ['article','aside','blockquote','caption','dd','div','dl','dt','figcaption','figure','footer','form','h1','h2','h3','h4','h5','h6','header','hr','li','main','nav','ol','p','pre','section','table','ul'], true);
    }

    private function phrasing(string $tag): bool
    {
        return in_array($tag, ['p','span','abbr','h1','h2','h3','h4','h5','h6','em','strong','code','q','label','sub','sup','rb','rt','rp','b','i','s','small','u','button'], true);
    }

    /** @param list<string> $ancestors */
    private function hasPhrasingAncestor(array $ancestors): bool
    {
        foreach ($ancestors as $tag) {
            if ($this->phrasing($tag)) {
                return true;
            }
        }
        return false;
    }

    private function interactive(Element $element): bool
    {
        return in_array($element->tag, ['a','button','select','textarea','label'], true)
            || ($element->tag === 'input' && ($element->attributes['type'] ?? '') !== 'hidden')
            || isset($element->attributes['tabindex']);
    }

    private function validLabel(Element $label): bool
    {
        $controls = [];
        $stack = $label->children;
        while ($stack !== []) {
            $node = array_pop($stack);
            if (!$node instanceof Element) {
                continue;
            }
            if ($node->tag === 'label') {
                return false;
            }
            if (in_array($node->tag, ['button','select','textarea','meter','output','progress'], true) || $node->tag === 'input' && ($node->attributes['type'] ?? '') !== 'hidden') {
                $controls[] = $node;
                if (count($controls) > 1 || isset($label->attributes['for']) && ($node->attributes['id'] ?? '') !== $label->attributes['for']) {
                    return false;
                }
            }
            array_push($stack, ...$node->children);
        }
        return true;
    }

    /** @return array<string,string> */
    private function segmentAttributes(Element $element, bool $first): array
    {
        $attrs = $element->attributes;
        if (!$first) {
            unset($attrs['id']);
        }
        return $attrs;
    }

    /** @param array<string,string> $attributes
     * @param list<HtmlNode> $children */
    private function make(string $tag, array $attributes = [], array $children = []): Element
    {
        // Charge before creating each split/wrapper object, not after expansion.
        $this->chargeParts($tag, $attributes);
        return new Element($tag, $attributes, $children);
    }

    private function charge(HtmlNode $node): void
    {
        if ($node instanceof Element) {
            $this->chargeParts($node->tag, $node->attributes);
        } elseif ($node instanceof Text) {
            $this->chargeParts('', [], strlen($node->value));
        }
    }

    /** @param array<string,string> $attributes */
    private function chargeParts(string $tag, array $attributes, int $textBytes = 0): void
    {
        if (++$this->nodes > $this->limits->maximumNodes * 8) {
            throw new DerivationException('The cumulative HTML repair node budget was exceeded.');
        }
        $bytes = $textBytes + strlen($tag) * 2 + ($tag === '' ? 0 : 5);
        foreach ($attributes as $name => $value) {
            $bytes += strlen($name) + strlen($value) + 4;
        }
        if ($bytes > $this->limits->maximumOutputBytes - $this->bytes) {
            throw new DerivationException('The cumulative HTML repair byte budget was exceeded.');
        }
        $this->bytes += $bytes;
    }

    /** @param list<string> $names */
    private function removeAttributes(Element $element, array $names): void
    {
        foreach ($names as $name) {
            unset($element->attributes[$name]);
        }
    }

    /** @param list<HtmlNode> $after */
    private function extractCaptionBlocks(Element $caption, array &$after): void
    {
        $remaining = [];
        foreach ($caption->children as $child) {
            if ($child instanceof Element && in_array($child->tag, ['table','ul','ol','dl'], true)) {
                $after[] = $child;
            } else {
                if ($child instanceof Element) {
                    $this->extractCaptionBlocks($child, $after);
                }
                $remaining[] = $child;
            }
        }
        $caption->children = $remaining;
    }
}
