<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PHPUnit\Framework\TestCase;

/** Reference 4.3.7.1: structural defaults precede explicit HTML/CSS attributes. */
final class StructureAttributePrecedenceTest extends TestCase
{
    public function testExplicitHtmlTitleOverridesStructuralExpansion(): void
    {
        $html = (new HtmlDeriver())->derive(new TaggedDocument('expansion.pdf', [
            new StructureElement(
                'abbreviation',
                'Span',
                [new TextContent('US')],
                properties: new StructureProperties(expansion: 'United States'),
                attributes: [new AttributeObject('HTML-5.00', ['title' => 'The United States of America'])]
            ),
        ]))->html;
        self::assertStringContainsString('<abbr', $html);
        self::assertStringContainsString('title="The United States of America"', $html);
        self::assertStringNotContainsString('title="United States"', $html);
    }

    public function testExpansionTitleRemainsWhenNoAttributeOverridesIt(): void
    {
        $html = (new HtmlDeriver())->derive(new TaggedDocument('expansion.pdf', [
            new StructureElement(
                'abbreviation',
                'Span',
                [new TextContent('US')],
                properties: new StructureProperties(expansion: 'United States')
            ),
        ]))->html;
        self::assertStringContainsString('title="United States"', $html);
    }

    public function testExplicitListBodyStylesOverrideItsInlineDefault(): void
    {
        foreach ([
            new AttributeObject('CSS-3.00', ['display' => 'block']),
            new AttributeObject('HTML-5.00', ['style' => 'display:block']),
        ] as $attribute) {
            $html = (new HtmlDeriver())->derive(new TaggedDocument('list.pdf', [
                new StructureElement('list', 'L', [new StructureElement('item', 'LI', [
                    new StructureElement('label', 'Lbl', [new TextContent('1.')]),
                    new StructureElement('body', 'LBody', [new TextContent('Item')], attributes: [$attribute]),
                ])]),
            ]))->html;
            self::assertStringContainsString('data-pdf-se-type="LBody"', $html);
            self::assertStringContainsString('style="display:block"', $html);
            self::assertStringNotContainsString('display:inline', $html);
        }
    }

    public function testHtmlStyleOverridesLayoutAndCssOverridesHtmlStyle(): void
    {
        $document = new TaggedDocument('priority.pdf', [new StructureElement('p', 'P', [new TextContent('Text')], attributes: [
            new AttributeObject('CSS-3.00', ['color' => 'green']),
            new AttributeObject('HTML-5.00', ['style' => 'color:blue;display:inline']),
            new AttributeObject('Layout', ['Color' => [1, 0, 0], 'Placement' => 'Block', 'Padding' => 3]),
        ])]);
        $html = (new HtmlDeriver())->derive($document)->html;
        self::assertStringContainsString('color:green', $html);
        self::assertStringContainsString('display:inline', $html);
        self::assertStringContainsString('padding:4px', $html);
        self::assertStringNotContainsString('color:blue', $html);
        self::assertStringNotContainsString('display:block', $html);
    }

    public function testListLabelIgnoresPlacementFromAttributesAndSharedClasses(): void
    {
        $label = new StructureElement('label', 'Lbl', [new TextContent('1.')], properties: new StructureProperties(classes: ['shared']), attributes: [
            new AttributeObject('Layout', ['Placement' => 'End']),
        ]);
        $document = new TaggedDocument('placement.pdf', [
            new StructureElement('list', 'L', [new StructureElement('item', 'LI', [$label, new StructureElement('body', 'LBody', [new TextContent('Item')])])]),
            new StructureElement('other', 'P', [new TextContent('Block')], properties: new StructureProperties(classes: ['shared'])),
        ], classMap: ['shared' => [new AttributeObject('Layout', ['Placement' => 'Block', 'Color' => [1, 0, 0]])]]);
        $html = (new HtmlDeriver())->derive($document)->html;
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $labelNode = $dom->getElementById('pdf-se-3');
        self::assertInstanceOf(\DOMElement::class, $labelNode);
        self::assertTrue($labelNode->hasAttribute('data-pdf-list-label'));
        self::assertStringNotContainsString('float:', $labelNode->getAttribute('style'));
        self::assertStringNotContainsString('display:', $labelNode->getAttribute('style'));
        self::assertStringContainsString(':not([data-pdf-list-label]):where(:not([data-pdf-math-layout])){display:block}', $html);
        self::assertStringContainsString('color:rgb(255, 0, 0)', $labelNode->getAttribute('style'));
    }

    public function testExplicitCssOnListLabelRemainsDespiteIgnoredPlacement(): void
    {
        $html = (new HtmlDeriver())->derive(new TaggedDocument('label.pdf', [new StructureElement('list', 'L', [
            new StructureElement('item', 'LI', [new StructureElement('label', 'Lbl', [new TextContent('Label')], attributes: [
                new AttributeObject('Layout', ['Placement' => 'End']), new AttributeObject('CSS-3.00', ['display' => 'block']),
            ])]),
        ])]))->html;
        self::assertStringContainsString('style="display:block"', $html);
        self::assertStringNotContainsString('float:right', $html);
    }

    public function testExplicitLinkHrefOverridesPdfAnnotationDestination(): void
    {
        $html = (new HtmlDeriver())->derive(new TaggedDocument('links.pdf', [new StructureElement('link', 'Link', [
            new \PDF\Html\Model\LinkAnnotation(uri: 'https://pdf.example/'), new TextContent('Link'),
        ], attributes: [new AttributeObject('HTML-5.00', ['href' => 'https://html.example/'])])]))->html;
        self::assertStringContainsString('href="https://html.example/"', $html);
        self::assertStringNotContainsString('https://pdf.example/', $html);
    }
}
