<?php

declare(strict_types=1);

namespace PDF\Html\Attributes;

use PDF\Html\Model\AttributeObject;
use PDF\Html\Html\AttributeName;
use PDF\Html\Html\CssDeclarations;

/**
     * Deriving HTML 1.2 §§4.3.7.1–12. Resource and active-content policy belongs to the output boundary.
     */
final class AttributeDeriver
{
    private const HTML = 'http://www.w3.org/1999/xhtml';
    private const MATHML = 'http://www.w3.org/1998/Math/MathML';
    /**
     * @param list<AttributeObject> $attributes
     */
    public function derive(array $attributes, ?string $namespace = null, ?string $namespaceIdentity = null): DerivedAttributes
    {
        $ordered = [];
        foreach ($attributes as $index => $attribute) {
            $ordered[] = [$this->priority($attribute), $index, $attribute];
        }
        usort($ordered, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        $html = $css = $diagnostics = $htmlStyleProperties = [];
        $layoutDisplay = false;
        $placement = null;
        $position = $numbering = $noteType = null;
        $printField = false;
        $tableHeaders = null;
        foreach ($ordered as [, , $attribute]) {
            $values = $attribute->values;
            $owner = $attribute->owner;
            if (isset($values['NoteType']) && is_string($values['NoteType'])) {
                $noteType = $values['NoteType'];
            }
            if ($owner === 'UserProperties') {
                foreach (($values['P'] ?? []) as $property) {
                    if (!is_array($property) || !isset($property['N']) || !is_string($property['N'])) {
                        continue;
                    }
                    $name = (new AttributeName())->userProperty($property['N']);
                    foreach (['V', 'F', 'H'] as $suffix) {
                        if (array_key_exists($suffix, $property)) {
                            $html['data-pdf-up-'.$name.'-'.strtolower($suffix)] = $this->string($property[$suffix]);
                        }
                    }
                }
            } elseif ($owner === 'List') {
                if (isset($values['ListNumbering']) && is_string($values['ListNumbering'])) {
                    $numbering = $values['ListNumbering'];
                    $styles = ['Disc' => 'disc', 'Circle' => 'circle', 'Square' => 'square', 'Decimal' => 'decimal', 'UpperRoman' => 'upper-roman', 'LowerRoman' => 'lower-roman', 'UpperAlpha' => 'upper-alpha', 'LowerAlpha' => 'lower-alpha', 'None' => 'none'];
                    if (isset($styles[$numbering])) {
                        $css['list-style-type'] = $styles[$numbering];
                    }
                }
                if (isset($values['ContinuedList']) || isset($values['ContinuedFrom'])) {
                    $diagnostics[] = 'ContinuedList/ContinuedFrom require an application continuation mechanism and were not projected.';
                }
            } elseif ($owner === 'Table') {
                foreach (['ColSpan' => 'colspan', 'RowSpan' => 'rowspan', 'Headers' => 'headers', 'Scope' => 'scope', 'Short' => 'abbr'] as $key => $target) {
                    if (array_key_exists($key, $values)) {
                        $value = $this->string($values[$key]);
                        if ($key === 'Headers') {
                            $tableHeaders = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                        }
                        if ($key === 'Scope') {
                            unset($html['scope'], $html['data-pdf-table-scope']);
                        }
                        if ($key === 'Scope' && $value === 'Both') {
                            $html['data-pdf-table-scope'] = 'Both';
                            $diagnostics[] = 'Scope Both has no HTML scope value; explicit Headers associations are retained when supplied.';
                        } else {
                            $html[$target] = $key === 'Scope' ? match ($value) {
                                'Column' => 'col', 'Row' => 'row', default => strtolower($value)
                            } : $value;
                        }
                    }
                }
                if (isset($values['TBorderStyle'])) {
                    $this->layout($css, 'BorderStyle', $values['TBorderStyle'], $diagnostics);
                }
                if (isset($values['TPadding'])) {
                    $css['padding'] = $this->lengths($values['TPadding']);
                }
            } elseif ($owner === 'Layout') {
                foreach ($values as $key => $value) {
                    if (in_array($key, ['O','NS'], true)) {
                        continue;
                    }
                    if ($key === 'TextPosition' && in_array($value, ['Sub','Sup'], true)) {
                        $position = strtolower($value);
                    } else {
                        $hadDisplay = isset($css['display']);
                        $this->layout($css, $key, $value, $diagnostics);
                        if ($key === 'Placement' && in_array($value, ['Block', 'Inline'], true)) {
                            $placement = strtolower((string) $value);
                            $layoutDisplay = true;
                        } elseif (!$hadDisplay && isset($css['display'])) {
                            $layoutDisplay = true;
                        }
                    }
                }
            } elseif ($owner === 'PrintField') {
                $printField = true;
                foreach (['Role','Checked'] as $key) {
                    if (isset($values[$key])) {
                        $html['data-pdf-printfield-'.strtolower($key)] = $this->string($values[$key]);
                    }
                }
            } elseif (str_starts_with($owner, 'CSS-')) {
                foreach ($values as $key => $value) {
                    if (!in_array($key, ['O','NS'], true)) {
                        $css[$this->cssPropertyName($key)] = $this->string($value);
                        unset($htmlStyleProperties[$this->cssPropertyName($key)]);
                        if (strtolower($key) === 'display') {
                            $layoutDisplay = false;
                        }
                    }
                }
            } elseif (str_starts_with($owner, 'HTML-') || str_starts_with($owner, 'ARIA-') || ($owner === 'NSO' && $namespace !== null && $attribute->namespace === $namespace && $namespaceIdentity !== null && $attribute->namespaceIdentity === $namespaceIdentity)) {
                foreach ($values as $key => $value) {
                    if (in_array($key, ['O','NS'], true)) {
                        continue;
                    }
                    $name = strtolower($key);
                    if ($name === 'headers') {
                        $tableHeaders = null;
                    }
                    if (is_bool($value) && in_array($name, ['disabled','readonly','required','multiple','checked','selected','hidden','autofocus','novalidate','formnovalidate'], true)) {
                        if ($value) {
                            $html[$name] = '';
                        } else {
                            unset($html[$name]);
                        }
                    } else {
                        $html[$name] = $this->string($value);
                        if ($name === 'style') {
                            foreach ((new CssDeclarations($html[$name]))->pairs() as [$property, $cssValue]) {
                                $property = $this->cssPropertyName($property);
                                $css[$property] = $cssValue;
                                $htmlStyleProperties[$property] = true;
                                if ($property === 'display') {
                                    $layoutDisplay = false;
                                }
                            }
                        }
                    }
                }
            } elseif ($owner === 'NSO') {
                $diagnostics[] = 'A namespace-specific attribute object did not match the target namespace and was ignored.';
            } else {
                $diagnostics[] = 'Unsupported attribute owner: '.$owner.'.';
            }
        }
        return new DerivedAttributes($html, $css, $position, $numbering, $printField, $noteType, array_values(array_unique($diagnostics)), $tableHeaders, $layoutDisplay, $placement, array_keys($htmlStyleProperties));
    }
    /** CSS custom properties are case-sensitive; ordinary CSS property names are not. */
    private function cssPropertyName(string $name): string
    {
        return str_starts_with($name, '--') ? $name : strtolower($name);
    }

    private function priority(AttributeObject $attribute): int
    {
        return match (true) {
            $attribute->owner === 'UserProperties' => 1,
            $attribute->owner === 'List' => 2,
            $attribute->owner === 'Table' => 3,
            $attribute->owner === 'Layout' => 4,
            $attribute->owner === 'PrintField' => 5,
            str_starts_with($attribute->owner, 'HTML-') => 6,
            $attribute->owner === 'NSO' && $attribute->namespace === self::HTML => 7,
            str_starts_with($attribute->owner, 'CSS-') => 8,
            str_starts_with($attribute->owner, 'ARIA-') => 9,
            $attribute->owner === 'NSO' && $attribute->namespace === self::MATHML => 10,
            default => 0,
        };
    }
    /**
     * @param array<string,string> $css
     * @param list<string> $diagnostics
     */
    private function layout(array &$css, string $key, mixed $value, array &$diagnostics): void
    {
        $colors = ['BackgroundColor' => 'background-color','BorderColor' => 'border-color','Color' => 'color','TextDecorationColor' => 'text-decoration-color'];
        $lengths = ['BorderThickness' => 'border-width','Padding' => 'padding','TPadding' => 'padding','TextIndent' => 'text-indent','BaselineShift' => 'baseline-shift'];
        if (isset($colors[$key])) {
            $css[$colors[$key]] = $this->color($value);
        } elseif (isset($lengths[$key])) {
            $css[$lengths[$key]] = $this->lengths($value);
        } elseif ($key === 'Placement') {
            if (in_array($value, ['Block','Inline'], true)) {
                $css['display'] = strtolower((string) $value);
            } elseif (in_array($value, ['Before','Start','End'], true)) {
                $css['float'] = $value === 'End' ? 'right' : 'left';
            }
        } elseif ($key === 'WritingMode') {
            $modes = ['LrTb' => 'horizontal-tb','RlTb' => 'horizontal-tb','TbRl' => 'vertical-rl','TbLr' => 'vertical-lr'];
            if (is_string($value) && isset($modes[$value])) {
                $css['writing-mode'] = $modes[$value];
                if ($value === 'RlTb') {
                    $css['direction'] = 'rtl';
                }
            }
        } elseif (in_array($key, ['SpaceBefore','SpaceAfter','StartIndent','EndIndent'], true)) {
            $margins = ['SpaceBefore' => 'margin-top','SpaceAfter' => 'margin-bottom','StartIndent' => 'margin-left','EndIndent' => 'margin-right'];
            $css[$margins[$key]] = $this->lengths($value);
            $css['display'] ??= 'block';
        } elseif ($key === 'LineHeight') {
            $css['line-height'] = is_numeric($value) ? $this->lengths($value) : 'normal';
        } elseif ($key === 'TextDecorationThickness') {
            $css['text-decoration-thickness'] = $this->lengths($value);
            $diagnostics[] = 'TextDecorationThickness uses the modern CSS text-decoration-thickness property.';
        } elseif (in_array($key, ['TextAlign','BorderStyle','TextDecorationType','RubyAlign','RubyPosition'], true)) {
            $targets = ['TextAlign' => 'text-align','BorderStyle' => 'border-style','TextDecorationType' => 'text-decoration','RubyAlign' => 'ruby-align','RubyPosition' => 'ruby-position'];
            $names = ['LineThrough' => 'line-through','Distribute' => 'space-between','Warichu' => 'inter-character','Start' => 'start','End' => 'end','Before' => 'over','After' => 'under'];
            $items = is_array($value) ? $value : [$value];
            if ($key === 'RubyAlign') {
                $names['Justify'] = 'space-around';
            }
            if ($key === 'BorderStyle' && count($items) === 4) {
                $items = [$items[0],$items[3],$items[1],$items[2]];
            }
            $css[$targets[$key]] = implode(' ', array_map(fn (mixed $item): string => $names[$this->string($item)] ?? strtolower($this->string($item)), $items));
        }
    }
    public function color(mixed $value): string
    {
        if (is_array($value) && count($value) === 4 && is_array($value[0])) {
            return implode(' ', array_map($this->color(...), [$value[0],$value[3],$value[1],$value[2]]));
        }
        if (!is_array($value) || count($value) !== 3) {
            return 'transparent';
        }
        $channels = array_map(static fn (mixed $channel): string => (string) (int) round(max(0.0, min(1.0, is_numeric($channel) ? (float) $channel : 0.0)) * 255), $value);
        return 'rgb('.implode(', ', $channels).')';
    }
    public function lengths(mixed $value): string
    {
        $items = is_array($value) ? $value : [$value];
        if (count($items) === 4) {
            $items = [$items[0], $items[3], $items[1], $items[2]];
        }
        return implode(' ', array_map(static function (mixed $number): string {
            if (!is_numeric($number) || !is_finite((float) $number)) {
                return '0px';
            }
            return rtrim(rtrim(number_format((float) $number * 4 / 3, 6, '.', ''), '0'), '.').'px';
        }, $items));
    }
    private function string(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map($this->string(...), $value));
        }
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_string($value), is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }
}
