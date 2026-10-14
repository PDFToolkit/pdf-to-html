<?php

declare(strict_types=1);

namespace PDF\Html\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;

/** Wraps interactive fields without moving their label/runtime identifier (4.3.6.5). */
final readonly class ExpansionText
{
    public function __construct(private DerivationLimits $limits = new DerivationLimits())
    {
    }

    public function wrap(Element $field, string $expansion): Element
    {
        if ($expansion === '') {
            return $field;
        }
        $title = $field->attributes['title'] ?? $expansion;
        if (strlen($title) > $this->limits->maximumOutputBytes) {
            throw new DerivationException('An expansion title exceeds the output byte limit.');
        }
        return new Element('abbr', ['title' => $title], [$field]);
    }
}
