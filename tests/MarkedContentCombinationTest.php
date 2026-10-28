<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\HtmlDeriver;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PHPUnit\Framework\TestCase;

/** Physical pages 54–55, clauses 4.4.7.1–5: presence differs from a nonempty value. */
final class MarkedContentCombinationTest extends TestCase
{
    public function testEveryCombinationHasOneOuterSpanAndAtMostOneInnerAbbreviation(): void
    {
        for ($mask = 0; $mask < 16; ++$mask) {
            $properties = new StructureProperties(
                language: ($mask & 1) !== 0 ? 'fr' : null,
                actualText: ($mask & 2) !== 0 ? 'remplacement' : null,
                alternateDescription: ($mask & 4) !== 0 ? 'description' : null,
                expansion: ($mask & 8) !== 0 ? 'expansion' : null,
            );
            $node = $this->paragraph($properties);
            self::assertSame(($mask & 2) !== 0 ? 'remplacement' : 'original', $node->textContent);
            $span = ($mask & 5) !== 0 || ($mask & 10) === 10;
            self::assertSame($span ? 1 : 0, $node->getElementsByTagName('span')->length, 'Property mask '.$mask);
            self::assertSame(($mask & 8) !== 0 ? 1 : 0, $node->getElementsByTagName('abbr')->length);
            if ($span) {
                self::assertSame('span', $node->firstChild?->nodeName);
                if (($mask & 8) !== 0) {
                    self::assertSame('abbr', $node->firstChild?->firstChild?->nodeName);
                }
            }
        }
    }

    public function testEmptyLanguageCancelsInheritedLanguageAndEmptyExpansionIsRetained(): void
    {
        $node = $this->paragraph(new StructureProperties(language: '', actualText: '', expansion: ''));
        $span = $node->getElementsByTagName('span')->item(0);
        $abbr = $node->getElementsByTagName('abbr')->item(0);
        self::assertInstanceOf(\DOMElement::class, $span);
        self::assertInstanceOf(\DOMElement::class, $abbr);
        self::assertTrue($span->hasAttribute('lang'));
        self::assertSame('', $span->getAttribute('lang'));
        self::assertTrue($abbr->hasAttribute('title'));
        self::assertSame('', $abbr->getAttribute('title'));
        self::assertSame('', $node->textContent);
    }

    private function paragraph(StructureProperties $properties): \DOMElement
    {
        $document = new TaggedDocument('marked.pdf', [new StructureElement('p', 'P', [
            new MarkedContent([new TextContent('original')], $properties),
        ])], language: 'en');
        $html = (new HtmlDeriver())->derive($document)->html;
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $paragraph = $dom->getElementsByTagName('p')->item(0);
        self::assertInstanceOf(\DOMElement::class, $paragraph);
        return $paragraph;
    }
}
