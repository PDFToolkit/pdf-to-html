<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\Html\Element;
use PDF\Html\Html\ListLayout;
use PDF\Html\Html\Text;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PHPUnit\Framework\TestCase;

final class ListLayoutTest extends TestCase
{
    public function testOnlyGeneratedListItemsWithBlockBodiesAreAdapted(): void
    {
        $paragraph = new Element('p', ['id' => 'first'], [new Text('First paragraph')]);
        $second = new Element('p', ['id' => 'second'], [new Text('Second paragraph')]);
        $body = new Element('div', ['data-pdf-se-type' => 'LBody'], [new Element('div', [], [$paragraph, $second])]);
        $item = new Element('li', ['data-pdf-se-type' => 'LI'], [new Element('span', ['data-pdf-se-type' => 'Lbl'], [new Text('1.')]), $body]);
        $layout = new ListLayout(new DerivationLimits());
        $layout->adapt($item);
        self::assertSame(1, $layout->count());
        self::assertArrayHasKey('data-pdf-list-layout', $item->attributes);
        self::assertArrayHasKey('data-pdf-list-first', $paragraph->attributes);
        self::assertArrayNotHasKey('data-pdf-list-first', $second->attributes);
        self::assertSame($paragraph, $body->children[0]->children[0]);
        self::assertSame($second, $body->children[0]->children[1]);
        self::assertStringContainsString(':where([data-pdf-list-layout])', $layout->css());
    }

    public function testFlatBodiesAndExplicitHtmlListsRemainUnchanged(): void
    {
        foreach ([false, true] as $native) {
            $item = new Element('li', $native ? ['data-pdf-se-type' => 'LI'] : [], [new Element('span', ['data-pdf-se-type' => 'Lbl'], [new Text('1.')]), new Element('div', ['data-pdf-se-type' => 'LBody'], [new Text('Flat body')])]);
            $layout = new ListLayout(new DerivationLimits());
            $layout->adapt($item);
            self::assertSame(0, $layout->count());
            self::assertSame('', $layout->css());
        }
    }

    public function testExplicitPdfStylesArePreservedOverGeneratedDefaults(): void
    {
        $document = new TaggedDocument('list.pdf', [new StructureElement('list', 'L', [new StructureElement('item', 'LI', [
            new StructureElement('label', 'Lbl', [new TextContent('1.')]),
            new StructureElement('body', 'LBody', [new StructureElement('paragraph', 'P', [new TextContent('Paragraph')], attributes: [new AttributeObject('CSS-3.00', ['margin-top' => '12px'])])]),
        ], attributes: [new AttributeObject('CSS-3.00', ['display' => 'block'])])])]);
        $result = (new HtmlDeriver())->derive($document);
        self::assertStringContainsString('style="display:block"', $result->html);
        self::assertStringContainsString('style="margin-top:12px"', $result->html);
        self::assertStringContainsString('data-pdf-list-layout', $result->html);
        self::assertStringContainsString(':where([data-pdf-list-first]){margin-block-start:0}', $result->css);
    }

    public function testTraversalBudgetRejectsCycles(): void
    {
        $cycle = new Element('div');
        $cycle->children[] = $cycle;
        $this->expectException(DerivationException::class);
        (new ListLayout(new DerivationLimits(maximumDepth: 2)))->adapt($cycle);
    }
}
