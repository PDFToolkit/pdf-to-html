<?php

declare(strict_types=1);

namespace PDF\Html\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;

/** Serializes into one bounded buffer, without recursively allocating subtree strings. */
final class Serializer
{
    private readonly DerivationLimits $limits;
    private int $bytes = 0;
    private int $nodes = 0;

    public function __construct(?DerivationLimits $limits = null)
    {
        $this->limits = $limits ?? new DerivationLimits();
    }

    public function serialize(HtmlNode $node): string
    {
        $this->bytes = $this->nodes = 0;
        $output = '';
        $this->write($node, $output, 0);
        return $output;
    }

    private function write(HtmlNode $node, string &$output, int $depth): void
    {
        if (++$this->nodes > $this->limits->maximumNodes * 8 || $depth > $this->limits->maximumDepth * 2) {
            throw new DerivationException('The serialization tree limit was exceeded.');
        }
        if ($node instanceof Text) {
            $this->escaped($node->value, $output);
            return;
        }
        if ($node instanceof Element && strlen($node->tag) > $this->limits->maximumOutputBytes - $this->bytes) {
            throw new DerivationException('An HTML element name exceeds the byte budget.');
        }
        if (!$node instanceof Element || preg_match('/\A[a-zA-Z][a-zA-Z0-9-]*\z/D', $node->tag) !== 1) {
            throw new DerivationException('Invalid output HTML node.');
        }
        $tag = strtolower($node->tag);
        $this->append('<'.$tag, $output);
        foreach ($node->attributes as $name => $value) {
            if (strlen($name) + 4 > $this->limits->maximumOutputBytes - $this->bytes) {
                throw new DerivationException('An HTML attribute name exceeds the byte budget.');
            }
            if (preg_match('/\A[a-zA-Z_:][a-zA-Z0-9_.:-]*\z/D', $name) !== 1) {
                throw new DerivationException('Invalid output attribute name.');
            }
            $this->append(' '.$name.'="', $output);
            $this->escaped($value, $output);
            $this->append('"', $output);
        }
        $this->append('>', $output);
        if (in_array($tag, ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'], true)) {
            if ($node->children !== []) {
                throw new DerivationException('A void HTML element cannot contain children.');
            }
            return;
        }
        if ($tag === 'script' || $tag === 'style') {
            $raw = '';
            $rawBytes = 0;
            foreach ($node->children as $child) {
                if (!$child instanceof Text) {
                    throw new DerivationException('Raw text elements require text children.');
                }
                if (++$this->nodes > $this->limits->maximumNodes * 8) {
                    throw new DerivationException('The serialization tree limit was exceeded.');
                }
                $this->validUtf8($child->value);
                $rawBytes += strlen($child->value);
                if ($rawBytes > $this->limits->maximumOutputBytes - $this->bytes) {
                    throw new DerivationException('The serialization byte limit was exceeded.');
                }
                $raw .= $child->value;
            }
            // Join before escaping: an end tag can straddle adjacent Text nodes.
            $occurrences = preg_match_all('~</'.$tag.'~i', $raw);
            $this->reserve($rawBytes + ($occurrences === false ? 0 : $occurrences));
            $output .= str_ireplace('</'.$tag, '<\\/'.$tag, $raw);
        } else {
            foreach ($node->children as $child) {
                $this->write($child, $output, $depth + 1);
            }
        }
        $this->append('</'.$tag.'>', $output);
    }

    private function append(string $value, string &$output): void
    {
        $this->reserve(strlen($value));
        $output .= $value;
    }

    private function escaped(string $value, string &$output): void
    {
        if (strlen($value) > $this->limits->maximumOutputBytes - $this->bytes) {
            throw new DerivationException('The serialization byte limit was exceeded.');
        }
        $this->validUtf8($value);
        $size = strlen($value) + substr_count($value, '&') * 4
            + (substr_count($value, '"') + substr_count($value, "'")) * 5
            + (substr_count($value, '<') + substr_count($value, '>')) * 3;
        // htmlspecialchars may expand text sixfold; check that allocation first.
        $this->reserve($size);
        $output .= htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function reserve(int $bytes): void
    {
        if ($bytes > $this->limits->maximumOutputBytes - $this->bytes) {
            throw new DerivationException('The serialization byte limit was exceeded.');
        }
        $this->bytes += $bytes;
    }

    private function validUtf8(string $value): void
    {
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new DerivationException('Derived HTML must contain valid UTF-8 without forbidden control characters.');
        }
    }
}
