<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\Html\ContentModelRepair;
use PDF\Html\Html\Element;
use PDF\Html\Html\ExpansionText;
use PDF\Html\Html\PageContentIdentifiers;
use PDF\Html\Html\Serializer;
use PDF\Html\Html\Text;
use PHPUnit\Framework\TestCase;

final class PageContentIdentifiersTest extends TestCase
{
    public function testPageIdentifierBelongsToTheActualTextParent(): void
    {
        $marker = $this->marker(2);
        $text = new Text('Actual content');
        $content = (new PageContentIdentifiers())->identify([$marker], [$text]);
        self::assertCount(1, $content);
        self::assertInstanceOf(Element::class, $content[0]);
        self::assertSame('PDF-Page-2', $content[0]->attributes['id']);
        self::assertSame($text, $content[0]->children[1]);
        self::assertInstanceOf(Element::class, $content[0]->children[0]);
        self::assertSame(['role' => 'doc-pagebreak', 'aria-label' => '2'], $content[0]->children[0]->attributes);
        self::assertSame('PDF-Page-2', $marker->attributes['id']);
    }

    public function testPhysicalPagesSharingActualTextKeepAllUniqueIdentifiers(): void
    {
        $content = (new PageContentIdentifiers())->identify([$this->marker(1), $this->marker(2)], [new Text('Shared replacement')]);
        $html = (new Serializer())->serialize($content[0]);
        self::assertSame(1, substr_count($html, 'id="PDF-Page-1"'));
        self::assertSame(1, substr_count($html, 'id="PDF-Page-2"'));
        self::assertSame(1, substr_count($html, 'Shared replacement'));
        self::assertSame(2, substr_count($html, 'role="doc-pagebreak"'));
    }

    public function testBlockReplacementUsesAFlowContentParentAndRetainsItsIdentifier(): void
    {
        $paragraph = new Element('p', ['id' => 'paragraph'], [new Text('Block content')]);
        $content = (new PageContentIdentifiers())->identify([$this->marker(3)], [$paragraph]);
        self::assertInstanceOf(Element::class, $content[0]);
        self::assertSame('div', $content[0]->tag);
        self::assertSame($paragraph, $content[0]->children[1]);
        self::assertSame('paragraph', $paragraph->attributes['id']);
    }

    public function testOmittedContentCannotIdentifyContentFromAnotherPage(): void
    {
        $identifiers = new PageContentIdentifiers();
        $empty = $identifiers->identify([$this->marker(1)], [new Text('')]);
        $following = $identifiers->identify([$this->marker(2)], [new Text('Page two')]);
        self::assertCount(1, $identifiers->diagnostics);
        self::assertSame('PDF-Page-1', $empty[0]->attributes['id']);
        self::assertStringNotContainsString('PDF-Page-1', (new Serializer())->serialize($following[0]));
    }

    public function testDuplicatePageIdentifiersAreRejectedAcrossCalls(): void
    {
        $identifiers = new PageContentIdentifiers();
        $identifiers->identify([$this->marker(1)], [new Text('First')]);
        $this->expectException(DerivationException::class);
        $identifiers->identify([$this->marker(1)], [new Text('Duplicate')]);
    }

    public function testCyclicHtmlContentIsRejected(): void
    {
        $cycle = new Element('span');
        $cycle->children[] = $cycle;
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('cycle');
        (new PageContentIdentifiers())->identify([$this->marker(1)], [$cycle]);
    }

    public function testNestedReplacementMarkersRespectTheDepthLimit(): void
    {
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('depth');
        (new PageContentIdentifiers(new DerivationLimits(maximumDepth: 1)))->identify([$this->marker(1), $this->marker(2), $this->marker(3)], [new Text('Replacement')]);
    }

    public function testNodeAndByteBudgetsAreCumulativeAcrossPages(): void
    {
        foreach ([new DerivationLimits(maximumNodes: 1), new DerivationLimits(maximumOutputBytes: 250)] as $limits) {
            $identifiers = new PageContentIdentifiers($limits);
            $thrown = false;
            try {
                foreach ([1, 2, 3] as $page) {
                    $identifiers->identify([$this->marker($page)], [new Text('Content')]);
                }
            } catch (DerivationException $exception) {
                $thrown = true;
                self::assertStringContainsString('cumulative', $exception->getMessage());
            }
            self::assertTrue($thrown);
        }
    }

    public function testFieldExpansionAndPageWrappingKeepItsLabelAndRuntimeIdentifier(): void
    {
        $field = new Element('input', ['id' => 'person', 'type' => 'text', 'title' => 'Identité complète']);
        $abbreviation = (new ExpansionText())->wrap($field, 'Identity');
        self::assertSame('abbr', $abbreviation->tag);
        self::assertSame(['title' => 'Identité complète'], $abbreviation->attributes);
        self::assertSame($field, $abbreviation->children[0]);
        $body = new Element('body', [], [new Element('label', ['for' => 'person'], [new Text('Name')]), ...(new PageContentIdentifiers())->identify([$this->marker(1)], [$abbreviation])]);
        $html = (new Serializer())->serialize((new ContentModelRepair())->repair($body)[0]);
        self::assertSame(1, substr_count($html, 'id="person"'));
        self::assertStringContainsString('for="person"', $html);
        self::assertStringContainsString('<abbr title="Identité complète"><input id="person"', $html);
        self::assertStringContainsString('id="PDF-Page-1"', $html);
    }

    public function testEmptyExpansionKeepsTheFieldUnchanged(): void
    {
        $field = new Element('input', ['id' => 'person']);
        self::assertSame($field, (new ExpansionText())->wrap($field, ''));
    }

    private function marker(int $page): Element
    {
        return new Element('span', ['id' => 'PDF-Page-' . $page, 'role' => 'doc-pagebreak', 'aria-label' => (string) $page]);
    }
}
