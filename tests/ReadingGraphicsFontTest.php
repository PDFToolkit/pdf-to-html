<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TextContent;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ISO 32000-2 Table 57, physical page 181: Font is [indirect-font number]. */
final class ReadingGraphicsFontTest extends TestCase
{
    public function testGraphicsStateCanSetTheFontWithoutTf(): void
    {
        $marked = $this->marked('BT /GS gs (A) Tj ET');
        self::assertSame(['A'], $this->texts($marked));
        $text = $marked->children[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertNotNull($text->source);
        self::assertContains('gs', array_column($text->source->instructions, 'operator'));
        self::assertNotNull($text->source->pageContext);
    }

    public function testGraphicsStateFontParticipatesInSaveAndRestore(): void
    {
        $marked = $this->marked('BT /GS gs (A) Tj q /Other gs (A) Tj Q (A) Tj ET', [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /ExtGState << /GS << /Font [8 0 R 12] >> /Other << /Font [9 0 R 24] >> >> >> /Contents 4 0 R >>',
            9 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding << /BaseEncoding /WinAnsiEncoding /Differences [65 /Z] >> >>',
        ]);
        self::assertSame(['A', 'Z', 'A'], $this->texts($marked));
    }

    public function testFormTextInheritsAGraphicsStateFontFromItsCaller(): void
    {
        $fixture = new PdfFixture();
        $marked = $this->marked('/GS gs /Fm Do', [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /ExtGState << /GS << /Font [8 0 R 12] >> >> /XObject << /Fm 9 0 R >> >> /Contents 4 0 R >>',
            9 => $fixture->stream('BT (A) Tj ET', '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Resources << >>'),
        ]);
        $form = $marked->children[0];
        self::assertInstanceOf(ContentGroup::class, $form);
        $text = $form->children[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertSame('A', $text->text);
        self::assertNotNull($text->source);
        self::assertNotNull($text->source->initialFont);
        self::assertNotNull($text->source->parent);
    }

    public function testResourceStateArrayAndSizeCanBeIndirect(): void
    {
        $marked = $this->marked('BT /GS gs (A) Tj ET', [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /ExtGState 9 0 R >> /Contents 4 0 R >>',
            9 => '<< /GS 10 0 R >>',
            10 => '<< /Type /ExtGState /Font 11 0 R >>',
            11 => '[8 0 R 12 0 R]',
            12 => '24',
        ]);
        self::assertSame(['A'], $this->texts($marked));
    }

    #[DataProvider('permittedSizes')]
    public function testFiniteZeroAndNegativeFontSizesRemainValid(string $size): void
    {
        $marked = $this->marked('BT /GS gs (A) Tj ET', [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /ExtGState << /GS << /Font [8 0 R ' . $size . '] >> >> >> /Contents 4 0 R >>',
        ]);
        self::assertSame(['A'], $this->texts($marked));
    }

    /** @return iterable<string, array{string}> */
    public static function permittedSizes(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-12.5'];
    }

    public function testAbsentOrNullGraphicsStateFontDoesNotReplaceTheCurrentFont(): void
    {
        $marked = $this->marked('BT /GS gs (A) Tj /Absent gs (A) Tj /Null gs (A) Tj ET', [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /ExtGState << /GS << /Font [8 0 R 12] >> /Absent << /ca 0.5 >> /Null << /Font null >> >> >> /Contents 4 0 R >>',
        ]);
        self::assertSame(['A', 'A', 'A'], $this->texts($marked));
    }

    #[DataProvider('invalidSettings')]
    public function testMalformedFontAndSizeSettingsFailDeterministically(string $setting): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('ExtGState Font');
        $fixture = new PdfFixture();
        $this->marked('BT /GS gs (A) Tj ET', [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /ExtGState << /GS << /Font ' . $setting . ' >> >> >> /Contents 4 0 R >>',
            20 => '<< /Type /NotFont >>',
            21 => $fixture->stream('not a font'),
            22 => '/Small',
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSettings(): iterable
    {
        yield 'not an array' => ['/Invalid'];
        yield 'empty array' => ['[]'];
        yield 'missing size' => ['[8 0 R]'];
        yield 'excess items' => ['[8 0 R 12 24]'];
        yield 'direct font dictionary' => ['[<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> 12]'];
        yield 'missing font reference' => ['[99 0 R 12]'];
        yield 'non-font dictionary' => ['[20 0 R 12]'];
        yield 'stream font reference' => ['[21 0 R 12]'];
        yield 'name size' => ['[8 0 R /Small]'];
        yield 'boolean size' => ['[8 0 R true]'];
        yield 'dictionary size' => ['[8 0 R << >>]'];
        yield 'string size' => ['[8 0 R (12)]'];
        yield 'missing size reference' => ['[8 0 R 99 0 R]'];
        yield 'non-numeric size reference' => ['[8 0 R 22 0 R]'];
    }

    #[DataProvider('invalidOperators')]
    public function testGraphicsStateResourceAndOperandTypesAreValidated(string $operator): void
    {
        $this->expectException(ReadingException::class);
        $this->marked('BT ' . $operator . ' (A) Tj ET');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOperators(): iterable
    {
        yield 'numeric name' => ['12 gs'];
        yield 'excess operands' => ['/GS /Other gs'];
        yield 'missing state resource' => ['/Missing gs'];
    }

    /** @param array<int, string> $overrides */
    private function marked(string $content, array $overrides = []): MarkedContent
    {
        $fixture = new PdfFixture();
        $file = $fixture->build($overrides + [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /ExtGState << /GS << /Font [8 0 R 12] >> >> >> /Contents 4 0 R >>',
            4 => $fixture->stream('/P <</MCID 0>> BDC ' . $content . ' EMC'),
        ]);
        $document = (new ReadPdfSource())->read((new DocumentReader())->read($file), 'graphics-font.pdf');
        $paragraph = $document->structure[0]->children[0];
        self::assertInstanceOf(StructureElement::class, $paragraph);
        $marked = $paragraph->children[0];
        self::assertInstanceOf(MarkedContent::class, $marked);
        return $marked;
    }

    /** @return list<string> */
    private function texts(MarkedContent $marked): array
    {
        $texts = [];
        foreach ($marked->children as $child) {
            self::assertInstanceOf(TextContent::class, $child);
            $texts[] = $child->text;
        }
        return $texts;
    }
}
