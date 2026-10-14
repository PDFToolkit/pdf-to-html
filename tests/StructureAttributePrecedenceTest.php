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
}
