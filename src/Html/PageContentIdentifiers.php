<?php

declare(strict_types=1);

namespace PDF\Html\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;

/** Identifies explicitly associated content parents (reference 4.4, physical page 52). */
final class PageContentIdentifiers
{
    private readonly DerivationLimits $limits;
    private readonly ContentModelRepair $contentModel;
    private int $nodes = 0;
    private int $bytes = 0;
    /** @var array<string, true> */
    private array $identified = [];
    /** @var array<int, true> */
    private array $active = [];
    /** @var list<string> */
    public array $diagnostics = [];

    public function __construct(?DerivationLimits $limits = null)
    {
        $this->limits = $limits ?? new DerivationLimits();
        $this->contentModel = new ContentModelRepair($this->limits);
    }

    /**
     * Markers belong to this content, rather than to the next arbitrary HTML sibling.
     * Multiple physical pages sharing one replacement use nested content parents.
     * @param list<Element> $markers
     * @param list<HtmlNode> $content
     * @return list<HtmlNode>
     */
    public function identify(array $markers, array $content): array
    {
        if ($markers === []) {
            return $content;
        }
        $localIds = [];
        foreach ($markers as $marker) {
            $id = $marker->attributes['id'] ?? '';
            if ($marker->tag !== 'span' || $marker->children !== [] || ($marker->attributes['role'] ?? '') !== 'doc-pagebreak' || preg_match('/\APDF-Page-[1-9][0-9]*\z/D', $id) !== 1) {
                throw new DerivationException('A page content identifier requires an empty generated PDF page marker.');
            }
            if (isset($this->identified[$id]) || isset($localIds[$id])) {
                throw new DerivationException('A PDF page content identifier can be assigned only once in a document.');
            }
            $localIds[$id] = true;
            $this->charge($marker);
        }
        $target = null;
        $targetDepth = 0;
        foreach ($content as $index => $node) {
            $depth = 0;
            if ($this->content($node, 0, $depth) && $target === null) {
                $target = $index;
                $targetDepth = $depth;
            }
        }
        $this->identified += $localIds;
        if ($target === null) {
            foreach ($localIds as $id => $_) {
                $this->diagnostics[] = $id . ' has no derived content; its existing empty page marker was retained without associating content from another page.';
            }
            return [...$markers, ...$content];
        }
        $node = $content[$target];
        if (count($markers) + $targetDepth > $this->limits->maximumDepth * 2) {
            throw new DerivationException('The nested page content identifier depth limit was exceeded.');
        }
        foreach (array_reverse($markers) as $marker) {
            $id = $marker->attributes['id'];
            $attributes = $marker->attributes;
            unset($attributes['id']);
            $tag = $node instanceof Element && $this->contentModel->block(strtolower($node->tag)) ? 'div' : 'span';
            $this->chargeParts('span', $attributes);
            $this->chargeParts($tag, ['id' => $id]);
            $node = new Element($tag, ['id' => $id], [new Element('span', $attributes), $node]);
        }
        $identifiedContent = [];
        foreach ($content as $index => $item) {
            $identifiedContent[] = $index === $target ? $node : $item;
        }
        return $identifiedContent;
    }

    private function content(HtmlNode $node, int $depth, int &$maximumDepth): bool
    {
        $maximumDepth = max($depth, $maximumDepth);
        if ($depth > $this->limits->maximumDepth * 2) {
            throw new DerivationException('The page content identifier depth limit was exceeded.');
        }
        $this->charge($node);
        if ($node instanceof Text) {
            return $node->value !== '';
        }
        if (!$node instanceof Element) {
            throw new DerivationException('Page content identifiers require supported HTML nodes.');
        }
        $identity = spl_object_id($node);
        if (isset($this->active[$identity])) {
            throw new DerivationException('The page content identifier input contains a cycle.');
        }
        $this->active[$identity] = true;
        try {
            $tag = strtolower($node->tag);
            $hasContent = in_array($tag, ['img', 'input', 'textarea', 'select', 'button', 'canvas', 'svg', 'math', 'br', 'hr', 'wbr'], true);
            foreach ($node->children as $child) {
                $hasContent = $this->content($child, $depth + 1, $maximumDepth) || $hasContent;
            }
            if (in_array($tag, ['script', 'style', 'head'], true) || ($node->attributes['role'] ?? '') === 'doc-pagelist') {
                return false;
            }
            return $hasContent;
        } finally {
            unset($this->active[$identity]);
        }
    }

    private function charge(HtmlNode $node): void
    {
        if ($node instanceof Element) {
            $this->chargeParts($node->tag, $node->attributes);
        } elseif ($node instanceof Text) {
            $this->chargeParts('', [], strlen($node->value));
        }
    }

    /** @param array<string, string> $attributes */
    private function chargeParts(string $tag, array $attributes, int $textBytes = 0): void
    {
        if (++$this->nodes > $this->limits->maximumNodes * 8) {
            throw new DerivationException('The cumulative page content identifier node limit was exceeded.');
        }
        $bytes = $textBytes + strlen($tag) * 2 + ($tag === '' ? 0 : 5);
        foreach ($attributes as $name => $value) {
            $bytes += strlen($name) + strlen($value) + 4;
        }
        if ($bytes > $this->limits->maximumOutputBytes - $this->bytes) {
            throw new DerivationException('The cumulative page content identifier byte limit was exceeded.');
        }
        $this->bytes += $bytes;
    }
}
