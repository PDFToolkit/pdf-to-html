<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\GraphicProgram;
use PDF\Html\Model\ImageContent;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TextContent;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\TestCase;

final class GraphicProgramReadingTest extends TestCase
{
    public function testPathsClippingColourTransparencyShadingAndTextKeepTypedSource(): void
    {
        $bytes = 'q 1 0 0 1 7 8 cm 0.2 0.3 0.4 rg /GS gs /Figure <</MCID 0>> BDC 1 2 m 3 4 l 5 6 7 8 9 10 c W* 2 w [3 4] 1 d B* /Shade sh BT /F1 12 Tf 1 0 0 1 10 20 Tm (Hello) Tj ET EMC Q';
        $marked = $this->marked($bytes);
        self::assertInstanceOf(GraphicProgram::class, $marked->source);
        self::assertSame('BDC', $marked->source->instructions[$marked->source->firstInstruction]->operator);
        self::assertSame('EMC', $marked->source->instructions[$marked->source->lastInstruction]->operator);
        self::assertSame([0.0, 0.0, 200.0, 200.0], $marked->source->boundingBox);
        self::assertNull($marked->source->stream);
        self::assertSame(['q', 'cm', 'rg', 'gs', 'BDC', 'm', 'l', 'c', 'W*', 'w', 'd', 'B*', 'sh', 'BT', 'Tf', 'Tm', 'Tj', 'ET', 'EMC', 'Q'], array_column($marked->source->instructions, 'operator'));
        $path = $marked->children[0];
        $shading = $marked->children[1];
        $text = $marked->children[2];
        self::assertInstanceOf(GraphicContent::class, $path);
        self::assertInstanceOf(GraphicProgram::class, $path->source);
        self::assertSame('B*', $path->source->instructions[$path->source->firstInstruction]->operator);
        self::assertSame($marked->source->instructions, $path->source->instructions);
        self::assertSame(6, count($path->source->instructions[7]->operands));
        self::assertInstanceOf(NumericType::class, $path->source->instructions[7]->operands[0]);
        self::assertSame(5, $path->source->instructions[7]->operands[0]->value);
        self::assertInstanceOf(GraphicContent::class, $shading);
        self::assertInstanceOf(GraphicProgram::class, $shading->source);
        self::assertEquals(new NameType('Shade'), $shading->source->instructions[$shading->source->firstInstruction]->operands[0]);
        self::assertNotNull($shading->source->resources->get('Shading'));
        self::assertNotNull($shading->source->resources->get('ExtGState'));
        self::assertInstanceOf(TextContent::class, $text);
        self::assertSame('Hello', $text->text);
        self::assertInstanceOf(GraphicProgram::class, $text->source);
        $literal = $text->source->instructions[$text->source->firstInstruction]->operands[0];
        self::assertInstanceOf(StringType::class, $literal);
        self::assertSame('Hello', $literal->value);
    }

    public function testFormSourceKeepsCallerProgramStreamAndTransformedBoundingBox(): void
    {
        $marked = $this->marked('BT /F1 12 Tf ET /Figure <</MCID 0>> BDC q 1 0 0 1 10 20 cm 0.2 0.3 0.4 rg /GS gs /Fm Do Q EMC', [
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [-5 -10 200 200] /CropBox [0 0 100 80] /Rotate 90 /UserUnit 2 /Resources << /Font << /F1 8 0 R >> /XObject << /Fm 9 0 R >> /ExtGState << /GS <</ca 0.5 /CA 0.7>> >> >> /Contents 4 0 R >>',
            9 => $this->stream('/P <</MCID 2>> BDC BT (A) Tj ET 0 0 m 10 20 l S EMC', '/Type /XObject /Subtype /Form /BBox [0 0 10 20] /Matrix [2 0 0 3 1 2]'),
        ]);
        self::assertInstanceOf(GraphicProgram::class, $marked->source);
        self::assertSame([0.0, -200.0, 160.0, 0.0], $marked->source->boundingBox);
        $group = $marked->children[0];
        self::assertInstanceOf(ContentGroup::class, $group);
        self::assertInstanceOf(GraphicProgram::class, $group->source);
        self::assertSame('Do', $group->source->instructions[$group->source->firstInstruction]->operator);
        $form = $group->children[0];
        self::assertInstanceOf(MarkedContent::class, $form);
        self::assertInstanceOf(GraphicProgram::class, $form->source);
        self::assertSame($group->source, $form->source->parent);
        self::assertInstanceOf(EncodedStream::class, $form->source->stream);
        self::assertSame([44.0, -62.0, 164.0, -22.0], $form->source->boundingBox);
        self::assertSame([0.0, -4.0, 6.0, 0.0, 44.0, -22.0], [$form->source->initialMatrix->a, $form->source->initialMatrix->b, $form->source->initialMatrix->c, $form->source->initialMatrix->d, $form->source->initialMatrix->e, $form->source->initialMatrix->f]);
        self::assertNotNull($form->source->initialFont);
        self::assertSame('/P <</MCID 2>> BDC BT (A) Tj ET 0 0 m 10 20 l S EMC', $form->source->document->streamData($form->source->stream));
    }

    public function testUnsupportedUnicodeKeepsOriginalCharacterCodesForRendering(): void
    {
        $marked = $this->marked('/P <</MCID 0>> BDC BT /F1 12 Tf <0041> Tj ET EMC', [8 => '<< /Type /Font /Subtype /Type0 /BaseFont /Unknown /Encoding /Identity-H >>']);
        $graphic = $marked->children[0];
        self::assertInstanceOf(GraphicContent::class, $graphic);
        self::assertSame('unmapped-text', $graphic->kind);
        self::assertInstanceOf(GraphicProgram::class, $graphic->source);
        $shown = $graphic->source->instructions[$graphic->source->firstInstruction]->operands[0];
        self::assertInstanceOf(StringType::class, $shown);
        self::assertSame("\0A", $shown->value);
        self::assertNotNull($graphic->source->resources->get('Font'));
    }

    public function testConvertedAndPlaceholderInlineImagesKeepTheirBinaryPrograms(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/inline-images.pdf');
        self::assertIsString($bytes);
        $source = (new ReadPdfSource())->read((new DocumentReader())->read(new FileBytes($bytes)), 'images.pdf');
        $figure = $source->structure[0]->children[0];
        self::assertInstanceOf(StructureElement::class, $figure);
        $marked = $figure->children[0];
        self::assertInstanceOf(MarkedContent::class, $marked);
        $first = $marked->children[0];
        self::assertInstanceOf(ImageContent::class, $first);
        self::assertInstanceOf(GraphicProgram::class, $first->source);
        self::assertSame(' EI ', $first->source->instructions[$first->source->firstInstruction]->inlineImage?->bytes);
        $figure = $source->structure[0]->children[7];
        self::assertInstanceOf(StructureElement::class, $figure);
        $marked = $figure->children[0];
        self::assertInstanceOf(MarkedContent::class, $marked);
        $placeholder = $marked->children[0];
        self::assertInstanceOf(GraphicContent::class, $placeholder);
        self::assertInstanceOf(GraphicProgram::class, $placeholder->source);
        self::assertSame("\0\0\0\xff", $placeholder->source->instructions[$placeholder->source->firstInstruction]->inlineImage?->bytes);
        $figure = $source->structure[0]->children[10];
        self::assertInstanceOf(StructureElement::class, $figure);
        $marked = $figure->children[0];
        self::assertInstanceOf(MarkedContent::class, $marked);
        $palette = $marked->children[0];
        self::assertInstanceOf(GraphicContent::class, $palette);
        self::assertInstanceOf(GraphicProgram::class, $palette->source);
        self::assertInstanceOf(\PDF\Html\Reading\InlineImageData::class, $palette->source->instructions[$palette->source->firstInstruction]->inlineImage);
        self::assertEquals(new NameType('Palette'), $palette->source->instructions[$palette->source->firstInstruction]->inlineImage->dictionary->get('ColorSpace'));
        self::assertNotNull($palette->source->resources->get('ColorSpace'));
    }

    public function testRetainedInstructionsConsumeTheDocumentNodeBudget(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('cumulative source nodes');
        $this->marked('/P <</MCID 0>> BDC ' . str_repeat('0 0 m 1 1 l S ', 100) . 'EMC', limits: new ReadingLimits(maximumNodes: 64));
    }

    /** @param array<int, string> $overrides */
    private function marked(string $content, array $overrides = [], ReadingLimits $limits = new ReadingLimits()): MarkedContent
    {
        $objects = array_replace([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R >> /Shading << /Shade <</ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 10 10]>> >> /ExtGState << /GS <</ca 0.5 /CA 0.7>> >> >> /Contents 4 0 R >>',
            4 => $this->stream($content),
            5 => '<< /Type /StructTreeRoot /K 6 0 R >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K 7 0 R >>',
            7 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K 0 >>',
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ], $overrides);
        ksort($objects);
        $bytes = "%PDF-2.0\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $size = max(array_keys($objects)) + 1;
        $bytes .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; ++$number) {
            $bytes .= isset($offsets[$number]) ? sprintf("%010d 00000 n \n", $offsets[$number]) : "0000000000 00000 f \n";
        }
        $bytes .= 'trailer << /Size ' . $size . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n";
        $source = (new ReadPdfSource($limits))->read((new DocumentReader())->read(new FileBytes($bytes)), 'graphics.pdf');
        $figure = $source->structure[0]->children[0];
        self::assertInstanceOf(StructureElement::class, $figure);
        $marked = $figure->children[0];
        self::assertInstanceOf(MarkedContent::class, $marked);
        return $marked;
    }

    private function stream(string $bytes, string $entries = ''): string
    {
        return '<< /Length ' . strlen($bytes) . ' ' . $entries . ">>\nstream\n" . $bytes . "\nendstream";
    }
}
