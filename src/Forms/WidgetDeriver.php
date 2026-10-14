<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

use PDF\Html\Attributes\AttributeDeriver;
use PDF\Html\Html\Element;
use PDF\Html\Html\Text;
use PDF\Html\Model\FormField;
use PDF\Html\Model\Widget;

/**
     * Tables 5–8, with explicit adaptations for invalid rich-text/readonly HTML combinations.
     */
final readonly class WidgetDeriver
{
    private FormActionPolicy $formActions;

    public function __construct(private ?AppearanceRenderer $appearanceRenderer = null, private string $formId = 'acroform_id', ?FormActionPolicy $formActions = null)
    {
        $this->formActions = $formActions ?? new FormActionPolicy();
    }
    public function derive(Widget $widget, string $id): WidgetRendering
    {
        $source = $widget->field;
        $diagnostics = [];
        $field = new Element('input', ['id' => $id, 'name' => $source->name, 'form' => $this->formId]);
        $siblings = [];
        $reset = $source->resetFormAction();
        $submit = $source->submitFormAction();
        if ($source->type === FieldType::Button && ($source->flags & 65536) !== 0 || $source->type === FieldType::Signature) {
            $field->tag = 'button';
            $field->attributes['type'] = 'button';
            if ($source->type === FieldType::Signature) {
                $field->attributes['data-pdf-signature-status'] = $widget->signatureStatus ?? 'not-validated';
                $diagnostics[] = 'A signature button reports PDF validation status only; the derived HTML is not signed.';
            } elseif ($submit !== null) {
                $field->attributes['data-pdf-form-action'] = 'SubmitForm';
                if ($submit->supportsHtmlSubmission() && $this->formActions->allowSubmit) {
                    $field->attributes['type'] = 'submit';
                    $field->attributes['formaction'] = $submit->target;
                    $field->attributes['formmethod'] = ($submit->flags & 8) !== 0 ? 'get' : 'post';
                    $field->attributes['formnovalidate'] = '';
                } else {
                    $diagnostics[] = $submit->supportsHtmlSubmission()
                        ? 'PDF SubmitForm selection, NoExport and missing-value rules require the explicitly enabled library form-action runtime; this button is inert.'
                        : 'SubmitForm export formats and flags outside HTML Form export cannot be represented by this reference mapping; provide an application export handler.';
                }
            } elseif ($reset !== null) {
                $field->attributes['data-pdf-form-action'] = 'ResetForm';
                if ($this->formActions->allowReset) {
                    $field->attributes['type'] = 'reset';
                } else {
                    $diagnostics[] = 'PDF ResetForm uses /DV and a /Fields selection, which native HTML reset cannot preserve; enable the library form-action runtime. This button is inert.';
                }
            } elseif ($source->actionType !== null) {
                $diagnostics[] = 'Button action '.$source->actionType.' needs an application action handler.';
            }
            if ($widget->appearance !== []) {
                if ($this->appearanceRenderer !== null) {
                    $field->children = $this->appearanceRenderer->render($widget->appearance);
                } else {
                    $diagnostics[] = 'A button appearance was not rendered because no AppearanceRenderer was supplied.';
                }
            }
            if ($widget->caption !== null) {
                $field->children[] = new Text($widget->caption);
            }
            if ($source->type === FieldType::Signature && $field->children === []) {
                $field->children[] = new Text('PDF signature: '.($widget->signatureStatus ?? 'not validated'));
            }
        } elseif ($source->type === FieldType::Button) {
            $field->attributes['type'] = ($source->flags & 32768) !== 0 ? 'radio' : 'checkbox';
            $option = $widget->optionIndex !== null ? ($source->options[$widget->optionIndex] ?? null) : (count($source->options) === 1 ? $source->options[0] : null);
            $states = array_values(array_filter($widget->appearanceStates, static fn (string $state): bool => $state !== 'Off'));
            $field->attributes['value'] = $option !== null ? $option->exportValue : ($states[0] ?? 'Yes');
            if ($widget->appearanceState !== 'Off') {
                $field->attributes['checked'] = '';
            }
            if (($source->flags & 33554432) !== 0 && $field->attributes['type'] === 'radio') {
                $diagnostics[] = 'RadiosInUnison is outside the specification mapping.';
            }
        } elseif ($source->type === FieldType::Choice) {
            if (($source->flags & 262144) !== 0) {
                $field->attributes['type'] = 'text';
                $field->attributes['value'] = $this->value($source);
                $field->attributes['list'] = $id.'-options';
                $datalist = new Element('datalist', ['id' => $id.'-options']);
                foreach ($source->options as $option) {
                    $datalist->children[] = new Element('option', ['value' => $option->exportValue], [new Text($option->displayValue)]);
                }
                $siblings[] = $datalist;
            } else {
                $field->tag = 'select';
                if (($source->flags & 131072) === 0) {
                    $field->attributes['size'] = '3';
                }
                if (($source->flags & 2097152) !== 0) {
                    $field->attributes['multiple'] = '';
                }
                $selected = is_array($source->value) ? $source->value : ($source->value === null ? [] : [$source->value]);
                foreach ($source->options as $index => $option) {
                    $attributes = ['value' => $option->exportValue];
                    if (in_array($option->exportValue, $selected, true) || in_array($index, $source->selectedIndices, true)) {
                        $attributes['selected'] = '';
                    }
                    $field->children[] = new Element('option', $attributes, [new Text($option->displayValue)]);
                }
            }
        } else {
            $password = ($source->flags & 8192) !== 0;
            $file = ($source->flags & 1048576) !== 0;
            $multiline = ($source->flags & 4096) !== 0 && !$password && !$file;
            $field->tag = $multiline ? 'textarea' : 'input';
            if (!$multiline) {
                $field->attributes['type'] = $password ? 'password' : ($file ? 'file' : 'text');
            }
            $value = $this->value($source);
            if (($source->flags & 33554432) !== 0 && $source->richText !== null) {
                $value = $this->plainRichText($source->richText);
                $diagnostics[] = 'Rich text was converted to text because input is void and textarea cannot contain HTML child markup.';
            }
            if ($multiline) {
                $field->children[] = new Text($value);
            } elseif ($file) {
                if ($value !== '') {
                    $diagnostics[] = 'A file input cannot be prefilled by HTML; its PDF value was omitted.';
                }
                if ($source->defaultValue !== null) {
                    $diagnostics[] = 'A browser cannot restore a PDF FileSelect /DV path; reset clears the user-selected file and never reads a local path.';
                }
            } else {
                $field->attributes['value'] = $value;
            }
            if ($source->maximumLength !== null && !$file) {
                $field->attributes['maxlength'] = (string) $source->maximumLength;
            } elseif ($source->maximumLength !== null) {
                $diagnostics[] = 'HTML file input has no maxlength attribute; the PDF FileSelect MaxLen was omitted.';
            }
            $field->attributes['spellcheck'] = ($source->flags & 4194304) !== 0 ? 'false' : 'true';
        }
        if (($source->flags & 1) !== 0) {
            $field->attributes['data-pdf-readonly'] = 'true';
            if ($field->tag === 'textarea' || $field->tag === 'input' && in_array($field->attributes['type'] ?? '', ['text','password'], true)) {
                $field->attributes['readonly'] = '';
            } else {
                // WAI-ARIA 1.2 aria-readonly supports checkbox/combobox/listbox, not radio/button/file.
                if ($field->tag === 'select' || $field->tag === 'input' && ($field->attributes['type'] ?? '') === 'checkbox') {
                    $field->attributes['aria-readonly'] = 'true';
                    $diagnostics[] = 'This control has no native HTML readonly behavior; aria-readonly describes its PDF state, which an application or enabled runtime must enforce.';
                } else {
                    $diagnostics[] = 'This control role does not support aria-readonly; data-pdf-readonly retains its PDF state, which an application or enabled runtime must enforce.';
                }
            }
        }
        if (($source->flags & 2) !== 0 && $field->tag !== 'button') {
            $field->attributes['required'] = '';
        } elseif (($source->flags & 2) !== 0) {
            $diagnostics[] = 'HTML button has no required attribute; the PDF field flag does not create an invalid HTML attribute.';
        }
        $attributes = new AttributeDeriver();
        $css = [];
        if ($widget->borderColor !== null) {
            $css['border-color'] = $this->widgetColor($widget->borderColor, $attributes);
        }
        if ($widget->backgroundColor !== null) {
            $css['background-color'] = $this->widgetColor($widget->backgroundColor, $attributes);
        }
        if ($widget->borderWidth !== null) {
            $css['border-width'] = $attributes->lengths($widget->borderWidth);
        }
        if ($widget->borderStyle !== null) {
            $css['border-style'] = match ($widget->borderStyle) {
                'D' => 'dashed','B' => 'outset','I' => 'inset','U' => 'solid', default => 'solid'
            };
            if ($widget->borderStyle === 'U') {
                $css['border-top-style'] = $css['border-left-style'] = $css['border-right-style'] = 'none';
            }
        }
        if ($widget->alignment !== null) {
            $css['text-align'] = [0 => 'left',1 => 'center',2 => 'right'][$widget->alignment] ?? 'left';
        }
        if ($widget->highlighting !== null) {
            $field->attributes['data-pdf-highlight'] = $widget->highlighting;
            $diagnostics[] = 'Widget highlighting is retained as metadata; browser interaction styling is application dependent.';
        }
        if (!$this->visible($widget)) {
            $css['display'] = 'none';
        }
        if ($css !== []) {
            $field->attributes['style'] = implode(';', array_map(static fn (string $key, string $value): string => $key.':'.$value, array_keys($css), array_values($css)));
        }
        return new WidgetRendering([$field, ...$siblings], $field, $diagnostics);
    }
    /**
     * @param list<int|float> $color
     */
    private function widgetColor(array $color, AttributeDeriver $attributes): string
    {
        if (count($color) === 1) {
            return $attributes->color([$color[0], $color[0], $color[0]]);
        }
        if (count($color) === 4) {
            [$cyan,$magenta,$yellow,$black] = $color;
            return $attributes->color([(1 - $cyan) * (1 - $black), (1 - $magenta) * (1 - $black), (1 - $yellow) * (1 - $black)]);
        }
        return $attributes->color($color);
    }
    private function value(FormField $field): string
    {
        return is_array($field->value) ? implode(', ', $field->value) : ($field->value ?? '');
    }
    private function visible(Widget $widget): bool
    {
        if ($widget->page === null || ($widget->annotationFlags & 3) !== 0) {
            return false;
        }
        [$x1,$y1,$x2,$y2] = $widget->rectangle;
        $left = min($x1, $x2);
        $right = max($x1, $x2);
        $bottom = min($y1, $y2);
        $top = max($y1, $y2);
        if ($right - $left <= 0 || $top - $bottom <= 0) {
            return false;
        }
        if ($widget->pageBox !== null) {
            [$a,$b,$c,$d] = $widget->pageBox;
            return $right > min($a, $c) && $left < max($a, $c) && $top > min($b, $d) && $bottom < max($b, $d);
        }
        return true;
    }
    private function plainRichText(string $richText): string
    {
        $document = new \DOMDocument();
        $oldErrors = libxml_use_internal_errors(true);
        try {
            if ($document->loadXML($richText, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return $document->textContent;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($oldErrors);
        }
        return html_entity_decode(strip_tags($richText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
