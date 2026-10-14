<?php

declare(strict_types=1);

namespace PDF\Html\Script;

use PDF\Html\AssetCollection;
use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\Html\Element;
use PDF\Html\Html\Text;

/** External script assets preserve JavaScript grammar without HTML raw-text ambiguity. */
final readonly class ScriptAssetEmitter
{
    public function __construct(private AssetCollection $assets, private DerivationLimits $limits)
    {
    }

    public function emit(Element $root): void
    {
        $stack = [$root];
        while ($stack !== []) {
            $element = array_pop($stack);
            if ($element->tag === 'script' && $element->children !== []) {
                if (isset($element->attributes['src'])) {
                    throw new DerivationException('A script cannot simultaneously provide inline code and a source URL.');
                }
                $source = '';
                foreach ($element->children as $child) {
                    if (!$child instanceof Text || strlen($child->value) > $this->limits->maximumAssetBytes - strlen($source)) {
                        throw new DerivationException('An inline script has invalid or oversized content.');
                    }
                    $source .= $child->value;
                }
                if (!mb_check_encoding($source, 'UTF-8') || str_contains($source, "\0")) {
                    throw new DerivationException('Derived JavaScript must be UTF-8 without NUL bytes.');
                }
                $filename = 'pdf-script-' . hash('sha256', $source) . '.js';
                $this->assets->add($filename, 'text/javascript', $source);
                $element->attributes['src'] = $filename;
                $element->children = [];
            }
            foreach ($element->children as $child) {
                if ($child instanceof Element) {
                    $stack[] = $child;
                }
            }
        }
    }
}
