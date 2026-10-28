<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Support;

use PDF\Html\Forms\AppearanceRenderer;
use PDF\Html\Forms\FieldType;
use PDF\Html\Html\Element;
use PDF\Html\Html\Text;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\FormField;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;

final readonly class WidgetHighlightCases implements AppearanceRenderer
{
    /** @return array<string, array{html: string, mode: string, override: bool, appearances: bool}> */
    public function all(): array
    {
        $cases = [];
        foreach (['N', 'I', 'O', 'P', 'T', null] as $mode) {
            $cases[strtolower($mode ?? 'default')] = $this->make($mode);
        }
        $cases['source-css-and-id'] = $this->make('I', override: true);
        $cases['outline-source-css-and-id'] = $this->make('O', override: true);
        $cases['push-appearances'] = $this->make('P', appearances: true);
        $cases['toggle-appearances'] = $this->make('T', appearances: true);
        $cases['invert-ignores-down'] = $this->make('I', appearances: true);
        $cases['content-widget'] = $this->make('I', interactive: false);
        foreach (['checkbox', 'radio'] as $control) {
            foreach (['N', 'I', 'O', 'P', 'T'] as $mode) {
                $cases[$control . '-' . strtolower($mode)] = $this->make($mode, control: $control);
            }
        }
        return $cases;
    }

    /** @return array{html: string, mode: string, override: bool, appearances: bool} */
    private function make(?string $mode, bool $override = false, bool $appearances = false, bool $interactive = true, string $control = 'button'): array
    {
        $widget = new Widget(
            new FormField('control', FieldType::Button, match ($control) {
                'checkbox' => 0, 'radio' => 32768, default => 65536
            }),
            page: 1,
            rectangle: [0, 0, 120, 30],
            caption: 'Activate',
            highlighting: $mode,
            borderColor: [32 / 255, 64 / 255, 96 / 255],
            backgroundColor: [0.9, 0.8, 0.7],
            borderStyle: 'S',
            borderWidth: 3,
            appearance: $appearances ? [new TextContent('Normal')] : [],
            downAppearance: $appearances ? [new TextContent('Pressed')] : [],
            rolloverAppearance: $appearances ? [new TextContent('Hover')] : []
        );
        $attributes = [new AttributeObject('CSS-3.00', ['width' => '180px', 'height' => '64px', 'font-size' => '16px'])];
        if ($override) {
            $attributes[] = new AttributeObject('HTML-5.00', ['id' => 'author-control']);
            $attributes[] = new AttributeObject('CSS-3.00', ['filter' => 'sepia(1)', 'border-color' => 'rgb(10, 20, 30)']);
        }
        $document = new TaggedDocument('widgets.pdf', [new StructureElement('widget-structure', $interactive ? 'Form' : 'Div', [$widget], attributes: $attributes)], language: 'en');
        return ['html' => (new HtmlDeriver(appearanceRenderer: $this))->derive($document)->html, 'mode' => $mode ?? 'I', 'override' => $override, 'appearances' => $appearances];
    }

    public function render(array $appearance): array
    {
        $nodes = [];
        foreach ($appearance as $node) {
            if (!$node instanceof TextContent) {
                throw new \LogicException('This appearance fixture accepts only text.');
            }
            $nodes[] = new Element('span', [], [new Text($node->text)]);
        }
        return $nodes;
    }
}
