<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Rendering;

use PDF\Html\ContentRenderer;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\ImageContent;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\TestCase;

final class ContentTextFallbackTest extends TestCase
{
    public function testMixedTjFallsBackAsOneInstructionWithoutDuplicatingItsDecodedSegments(): void
    {
        $document = $this->document('(Prior) Tj [(A) -20 (B) (C)] TJ (After) Tj');
        $marked = $document->structure[0]->children[0]->children[0];
        self::assertInstanceOf(MarkedContent::class, $marked);
        self::assertCount(3, $marked->children);
        [$before, $graphic, $after] = $marked->children;
        self::assertInstanceOf(TextContent::class, $before);
        self::assertSame('Prior', $before->text);
        self::assertInstanceOf(GraphicContent::class, $graphic);
        self::assertSame('unmapped-text', $graphic->kind);
        self::assertNotNull($graphic->source);
        self::assertSame('TJ', $graphic->source->instructions[$graphic->source->firstInstruction]->operator);
        self::assertSame($graphic->source->firstInstruction, $graphic->source->lastInstruction);
        self::assertInstanceOf(TextContent::class, $after);
        self::assertSame('After', $after->text);
        $renderer = new class () implements ContentRenderer {
            public int $calls = 0;
            public function render(GraphicContent|ContentGroup $content): ImageContent
            {
                ++$this->calls;
                return new ImageContent(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true), 'image/gif', 30, 15);
            }
        };
        $result = (new HtmlDeriver(renderer: $renderer))->derive($document);
        self::assertSame(1, $renderer->calls);
        self::assertStringContainsString('Prior', $result->html);
        self::assertStringContainsString('<img', $result->html);
        self::assertStringContainsString('After', $result->html);
        self::assertLessThan(strpos($result->html, '<img'), strpos($result->html, 'Prior'));
        self::assertGreaterThan(strpos($result->html, '<img'), strpos($result->html, 'After'));
        self::assertStringNotContainsString('AC', $result->html);
    }

    public function testAllDecodedTjSegmentsStillProduceOneUtf8Run(): void
    {
        $document = $this->document('[(A) -20 (C)] TJ');
        $marked = $document->structure[0]->children[0]->children[0];
        self::assertCount(1, $marked->children);
        self::assertInstanceOf(TextContent::class, $marked->children[0]);
        self::assertSame('AC', $marked->children[0]->text);
    }

    public function testActualTextReplacementBypassesUnknownGlyphsBeforeFallback(): void
    {
        $document = $this->document('[(A) (B) (C)] TJ', '/ActualText (Replacement)');
        $result = (new HtmlDeriver())->derive($document);
        self::assertStringContainsString('Replacement', $result->html);
        self::assertStringNotContainsString('<img', $result->html);
        self::assertSame([], array_values(array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'graphic-omitted')));
    }

    public function testInvalidTjOperandsAreNotHiddenByAnEarlierUnmappedSegment(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('text-showing operand has an invalid type');
        $this->document('[(B) /Invalid] TJ');
    }

    private function document(string $text, string $properties = ''): TaggedDocument
    {
        $fixture = new PdfFixture();
        $file = $fixture->build([
            4 => $fixture->stream('/P <</MCID 0 ' . $properties . '>> BDC BT /F1 12 Tf ' . $text . ' ET EMC'),
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding << /BaseEncoding /WinAnsiEncoding /Differences [66 /UnknownGlyphName] >> >>',
        ]);
        return (new ReadPdfSource())->read((new DocumentReader())->read($file), 'mixed-tj.pdf');
    }
}
