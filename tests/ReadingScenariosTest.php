<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\SourceNode;
use PDF\Html\Model\TextContent;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Original hand-built syntax independently exercises source graph invariants. */
final class ReadingScenariosTest extends TestCase
{
    /** @return iterable<string, array{array<int, string>}> */
    public static function invalidGraphs(): iterable
    {
        yield 'missing MCID' => [[7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 99 >>']];
        yield 'wrong parent' => [[7 => '<< /Type /StructElem /S /P /P 5 0 R /Pg 3 0 R /K 0 >>']];
        yield 'repeated child' => [[6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 7 0 R] >>']];
        yield 'structure cycle' => [[7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 6 0 R >>']];
        yield 'missing page context' => [[7 => '<< /Type /StructElem /S /P /P 6 0 R /K 0 >>']];
        yield 'page outside document' => [[7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 8 0 R /K 0 >>']];
        yield 'negative MCID' => [[7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K -1 >>']];
        yield 'namespace missing NS' => [[7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /NS << /Type /Namespace >> >>']];
    }

    #[DataProvider('invalidGraphs')]
    public function testMalformedGraphsAreExplicitlyRejected(array $overrides): void
    {
        $this->expectException(ReadingException::class);
        $this->read($overrides);
    }

    public function testFontStateRestorationArraysAndBothQuoteOperators(): void
    {
        $source = $this->read([3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R /F2 9 0 R >> >> /Contents 4 0 R >>', 4 => $this->stream('/P << /MCID 0 >> BDC BT /F1 12 Tf (A) Tj q /F2 12 Tf (A) Tj Q [(A) -120 (A)] TJ (A) \' 1 2 (A) " ET EMC'), 9 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding << /BaseEncoding /WinAnsiEncoding /Differences [65 /B] >> >>']);
        self::assertSame('ABAAAA', $this->text($source->structure[0]->children[0]->children));
    }

    public function testRepeatedReadsHaveIndependentBudgets(): void
    {
        $adapter = new ReadPdfSource(new ReadingLimits(maximumTextBytes: 1024));
        $document = (new DocumentReader())->read(new FileBytes($this->pdf([])));
        self::assertSame('Alpha', $this->text($adapter->read($document, 'one.pdf')->structure[0]->children[0]->children));
        self::assertSame('Alpha', $this->text($adapter->read($document, 'two.pdf')->structure[0]->children[0]->children));
    }

    public function testUnknownFontMappingProducesAnExplicitGraphicDiagnosticNode(): void
    {
        $source = $this->read([8 => '<< /Type /Font /Subtype /Type0 /BaseFont /Unknown /Encoding /Identity-H >>']);
        $node = $source->structure[0]->children[0]->children[0]->children[0];
        self::assertInstanceOf(\PDF\Html\Model\GraphicContent::class, $node);
        self::assertSame('unmapped-text', $node->kind);
        self::assertStringContainsString('without ToUnicode', $node->instructions);
    }

    public function testBinaryStructureIdentifiersDoNotUseTextEncoding(): void
    {
        $source = $this->read([7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /ID <ff0020> >>']);
        self::assertSame('pdf-id-ff0020', $source->structure[0]->children[0]->properties->id);
    }

    public function testInvalidOptionalAlternateDescriptionHasAnExplicitSourceDiagnostic(): void
    {
        $source = $this->read([7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /Alt <00> >>']);
        self::assertNull($source->structure[0]->children[0]->properties->alternateDescription);
        self::assertCount(1, $source->sourceDiagnostics);
        self::assertStringContainsString('/Alt', $source->sourceDiagnostics[0]);
    }

    public function testXmpCannotDeclareExternalEntities(): void
    {
        $this->expectException(ReadingException::class);
        $this->read([1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Metadata 9 0 R >>', 9 => $this->stream('<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///must-not-be-read">]><x>&secret;</x>', '/Type /Metadata /Subtype /XML')]);
    }

    public function testGraphicsNodesAlsoConsumeTheCumulativeNodeBudget(): void
    {
        $this->expectException(ReadingException::class);
        (new ReadPdfSource(new ReadingLimits(maximumNodes: 6)))->read((new DocumentReader())->read(new FileBytes($this->pdf([4 => $this->stream('/P <</MCID 0>> BDC 0 0 m 1 1 l S 0 0 m 1 1 l S 0 0 m 1 1 l S 0 0 m 1 1 l S 0 0 m 1 1 l S EMC')]))), 'graphics.pdf');
    }

    public function testToUnicodeRangeExpansionIsBoundedBeforeAllocation(): void
    {
        $this->expectException(ReadingException::class);
        $pdf = $this->pdf([8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 9 0 R >>', 9 => $this->stream('1 begincodespacerange <0000> <ffff> endcodespacerange 1 beginbfrange <0000> <ffff> <0041> endbfrange')]);
        (new ReadPdfSource(new ReadingLimits(maximumMappings: 8)))->read((new DocumentReader())->read(new FileBytes($pdf)), 'map.pdf');
    }

    public function testCyclicDestinationDictionariesAreRejectedWithoutRecursion(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('Destination dictionaries contain a cycle');
        $this->read([
            7 => '<< /Type /StructElem /S /Link /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 9 0 R >> >>',
            9 => '<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] /Dest 10 0 R >>',
            10 => '<< /D 10 0 R >>',
        ]);
    }

    public function testRepeatedEmptyNameTreeBranchesAreRejectedGlobally(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('cycle or repeated node');
        $this->read([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Names << /Dests 9 0 R >> >>',
            9 => '<< /Kids [10 0 R 10 0 R] >>',
            10 => '<< /Kids [11 0 R 11 0 R] >>',
            11 => '<< >>',
        ]);
    }

    public function testEmptyNameTreeNodesConsumeTheCumulativeBudget(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('source nodes budget');
        $pdf = $this->pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Names << /Dests 9 0 R >> >>',
            9 => '<< /Kids [10 0 R] >>',
            10 => '<< /Kids [11 0 R] >>',
            11 => '<< >>',
        ]);
        (new ReadPdfSource(new ReadingLimits(maximumNodes: 2)))->read((new DocumentReader())->read(new FileBytes($pdf)), 'tree.pdf');
    }

    public function testUnicodeExpansionConsumesTextBudgetBeforeConcatenation(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('source text budget');
        $pdf = $this->pdf([
            4 => $this->stream('/P <</MCID 0>> BDC BT /F1 12 Tf (AAAAAAAAAAAAAAAA) Tj ET EMC'),
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 9 0 R >>',
            9 => $this->stream('1 begincodespacerange <00> <ff> endcodespacerange 1 beginbfchar <41> <00660069> endbfchar'),
        ]);
        (new ReadPdfSource(new ReadingLimits(maximumTextBytes: 8)))->read((new DocumentReader())->read(new FileBytes($pdf)), 'expansion.pdf');
    }

    public function testWidgetUsesEffectiveCropBoxRatherThanMediaBox(): void
    {
        $source = $this->read([
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /CropBox [0 0 50 50] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Annots [9 0 R] >>',
            7 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 9 0 R >> >>',
            9 => '<< /Type /Annot /Subtype /Widget /Rect [100 100 150 150] /FT /Tx /T (OutsideCrop) /V (hidden) >>',
        ]);
        $widget = $source->structure[0]->children[0]->children[0];
        self::assertInstanceOf(\PDF\Html\Model\Widget::class, $widget);
        self::assertSame([0, 0, 50, 50], $widget->pageBox);
        $result = (new \PDF\Html\HtmlDeriver())->derive($source);
        self::assertStringContainsString('display:none', str_replace(' ', '', $result->html));
    }

    public function testWidgetAbsentFromEveryAnnotsArrayRemainsHiddenDespiteObjrPg(): void
    {
        $source = $this->read([
            7 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 9 0 R /Pg 3 0 R >> >>',
            9 => '<< /Type /Annot /Subtype /Widget /P 3 0 R /Rect [10 10 20 20] /FT /Tx /T (Detached) /V (hidden) >>',
        ]);
        $widget = $source->structure[0]->children[0]->children[0];
        self::assertInstanceOf(\PDF\Html\Model\Widget::class, $widget);
        self::assertNull($widget->page);
        $result = (new \PDF\Html\HtmlDeriver())->derive($source);
        self::assertStringContainsString('display:none', str_replace(' ', '', $result->html));
    }

    public function testNameAndByteStringDestinationNamespacesRemainDistinct(): void
    {
        $source = $this->read([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Names << /Dests 12 0 R >> /Dests << /same [9 0 R /Fit] >> >>',
            2 => '<< /Type /Pages /Kids [3 0 R 9 0 R] /Count 2 >>',
            7 => '<< /Type /StructElem /S /Link /P 6 0 R /Pg 3 0 R /K [<< /Type /OBJR /Obj 10 0 R >> << /Type /OBJR /Obj 11 0 R >> << /Type /OBJR /Obj 13 0 R >>] >>',
            9 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> >>',
            10 => '<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] /Dest (same) >>',
            11 => '<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] /Dest /same >>',
            12 => '<< /Names [(same) [3 0 R /Fit] <ff00> [9 0 R /Fit]] >>',
            13 => '<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] /Dest <ff00> >>',
        ]);
        $links = $source->structure[0]->children[0]->children;
        self::assertSame(1, $links[0]->pageTarget);
        self::assertSame(2, $links[1]->pageTarget);
        self::assertSame(2, $links[2]->pageTarget);
    }

    public function testUtf16XmpCannotBypassTheDtdPolicy(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('XMP metadata may not contain a DTD');
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY title "Expanded title">]><x>&title;</x>';
        $utf16 = iconv('UTF-8', 'UTF-16LE', $xml);
        self::assertNotFalse($utf16);
        $this->read([1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Metadata 9 0 R >>', 9 => $this->stream("\xff\xfe" . $utf16, '/Type /Metadata /Subtype /XML')]);
    }

    public function testInheritedUnicodeSnapshotsAreBudgetedBeforeArrayCopy(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('source mappings budget');
        $pdf = $this->pdf([
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 9 0 R >>',
            9 => $this->stream('1 beginbfchar <42> <0042> endbfchar', '/UseCMap 10 0 R'),
            10 => $this->stream('1 begincodespacerange <00> <ff> endcodespacerange 1 beginbfchar <41> <0041> endbfchar'),
        ]);
        (new ReadPdfSource(new ReadingLimits(maximumMappings: 4)))->read((new DocumentReader())->read(new FileBytes($pdf)), 'inheritance.pdf');
    }

    public function testImageDimensionsFollowCurrentTransformationAndGraphicsRestore(): void
    {
        $source = $this->read($this->imageObjects('/Figure <</MCID 0>> BDC q 20 0 0 30 10 10 cm /Im Do Q /Im Do EMC'));
        $images = $this->images($source->structure[0]->children[0]->children);
        self::assertCount(2, $images);
        self::assertSame(20.0, $images[0]->widthPoints);
        self::assertSame(30.0, $images[0]->heightPoints);
        self::assertSame(1.0, $images[1]->widthPoints);
        self::assertSame(1.0, $images[1]->heightPoints);
    }

    public function testMatrixCompositionPreservesRotationShearAndPageUserUnit(): void
    {
        $objects = $this->imageObjects('/Figure <</MCID 0>> BDC 0 2 -3 0 10 10 cm 4 1 2 5 3 4 cm /Im Do EMC');
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Rotate 90 /UserUnit 2 /Resources << /XObject << /Im 9 0 R >> >> /Contents 4 0 R >>';
        $source = $this->read($objects);
        $image = $this->images($source->structure[0]->children[0]->children)[0];
        self::assertEqualsWithDelta(2 * sqrt(73), $image->widthPoints, 1e-12);
        self::assertEqualsWithDelta(2 * sqrt(241), $image->heightPoints, 1e-12);
    }

    public function testNestedFormMatrixComposesWithThePaintingContext(): void
    {
        $objects = $this->imageObjects('/Figure <</MCID 0>> BDC q 2 0 0 3 5 6 cm /Fm Do Q EMC');
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /XObject << /Fm 10 0 R >> >> /Contents 4 0 R >>';
        $objects[10] = $this->stream('/Im Do', '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Matrix [4 1 2 5 7 8] /Resources << /XObject << /Im 9 0 R >> >>');
        $source = $this->read($objects);
        $image = $this->images($source->structure[0]->children[0]->children)[0];
        self::assertEqualsWithDelta(sqrt(73), $image->widthPoints, 1e-12);
        self::assertEqualsWithDelta(sqrt(241), $image->heightPoints, 1e-12);
    }

    public function testUnsupportedImageRetainsItsLogicalDimensions(): void
    {
        $objects = $this->imageObjects('/Figure <</MCID 0>> BDC 20 0 0 30 0 0 cm /Im Do EMC');
        $objects[9] = $this->stream("\x00\x00\x00\x00", '/Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceCMYK');
        $source = $this->read($objects);
        $graphic = $source->structure[0]->children[0]->children[0]->children[0];
        self::assertInstanceOf(\PDF\Html\Model\GraphicContent::class, $graphic);
        self::assertSame('unsupported-image', $graphic->kind);
        self::assertSame(20.0, $graphic->widthPoints);
        self::assertSame(30.0, $graphic->heightPoints);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMatrices(): iterable
    {
        yield 'degenerate matrix' => ['0 0 0 0 0 0 cm'];
        yield 'wrong coefficient count' => ['1 0 0 1 0 cm'];
        yield 'nonnumeric coefficient' => ['1 0 0 1 0 /bad cm'];
        yield 'overflowing composition' => [str_repeat('9', 200) . '.0 0 0 ' . str_repeat('9', 200) . '.0 0 0 cm'];
    }

    #[DataProvider('invalidMatrices')]
    public function testInvalidImageTransformationsAreExplicitlyRejected(string $matrix): void
    {
        $this->expectException(ReadingException::class);
        $this->read($this->imageObjects('/Figure <</MCID 0>> BDC ' . $matrix . ' /Im Do EMC'));
    }

    public function testInvalidPageUserUnitIsExplicitlyRejected(): void
    {
        $objects = $this->imageObjects('/Figure <</MCID 0>> BDC /Im Do EMC');
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /UserUnit -1 /Resources << /XObject << /Im 9 0 R >> >> /Contents 4 0 R >>';
        $this->expectException(ReadingException::class);
        $this->read($objects);
    }

    /** @return iterable<string, array{string, string}> */
    public static function associatedUrlMediaTypes(): iterable
    {
        yield 'HTML short extension' => ['https://example.test/page.htm', 'text/html'];
        yield 'HTML' => ['https://example.test/page.html', 'text/html'];
        yield 'XHTML' => ['https://example.test/page.xhtml', 'application/xhtml+xml'];
        yield 'CSS ignores query and fragment' => ['https://example.test/THEME.CSS?file=picture.png#script.js', 'text/css'];
        yield 'JavaScript' => ['https://example.test/behavior.js', 'text/javascript'];
        yield 'JPEG short extension' => ['https://example.test/picture.jpg', 'image/jpeg'];
        yield 'JPEG' => ['https://example.test/picture.JPEG', 'image/jpeg'];
        yield 'PNG' => ['https://example.test/picture.png', 'image/png'];
        yield 'GIF' => ['https://example.test/picture.gif', 'image/gif'];
        yield 'SVG' => ['https://example.test/picture.svg', 'image/svg+xml'];
        yield 'XML means MathML in table 9' => ['https://example.test/formula.xml', 'application/mathml+xml'];
        yield 'MathML' => ['https://example.test/formula.MathML#equation', 'application/mathml+xml'];
        yield 'query cannot add an extension' => ['https://example.test/download?file=theme.css', 'application/octet-stream'];
        yield 'directory is not a filename' => ['https://example.test/theme.css/', 'application/octet-stream'];
        yield 'unknown extension' => ['https://example.test/archive.zip', 'application/octet-stream'];
    }

    #[DataProvider('associatedUrlMediaTypes')]
    public function testUrlAssociatedFileTypesComeFromTheUrlPath(string $url, string $mediaType): void
    {
        $source = $this->read([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AF [9 0 R] >>',
            9 => '<< /Type /Filespec /FS /URL /F (' . $url . ') /AFRelationship /Supplement >>',
        ]);
        self::assertCount(1, $source->associatedFiles);
        self::assertSame($mediaType, $source->associatedFiles[0]->mediaType);
        self::assertSame($url, $source->associatedFiles[0]->url);
        self::assertNull($source->associatedFiles[0]->bytes);
    }

    public function testFileUrlClassificationNeverBypassesTheOutputPolicy(): void
    {
        $source = $this->read([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AF [9 0 R] >>',
            9 => '<< /Type /Filespec /FS /URL /F (file:///must-not-be-read/private.css) /AFRelationship /Supplement >>',
        ]);
        self::assertSame('text/css', $source->associatedFiles[0]->mediaType);
        self::assertNull($source->associatedFiles[0]->bytes);
        $result = (new \PDF\Html\HtmlDeriver(new \PDF\Html\DerivationPolicy(allowRemoteResources: true)))->derive($source);
        self::assertStringNotContainsString('file:///', $result->html);
        self::assertStringNotContainsString('must-not-be-read', $result->html);
    }

    /** @return array<int, string> */
    private function imageObjects(string $content): array
    {
        return [
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /XObject << /Im 9 0 R >> >> /Contents 4 0 R >>',
            4 => $this->stream($content),
            7 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K 0 >>',
            9 => $this->stream("\xff\x00\x00", '/Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB'),
        ];
    }

    /**
     * @param list<SourceNode> $nodes
     * @return list<\PDF\Html\Model\ImageContent>
     */
    private function images(array $nodes): array
    {
        $images = [];
        foreach ($nodes as $node) {
            if ($node instanceof \PDF\Html\Model\ImageContent) {
                $images[] = $node;
            } elseif ($node instanceof MarkedContent || $node instanceof \PDF\Html\Model\ContentGroup) {
                array_push($images, ...$this->images($node->children));
            }
        }
        return $images;
    }

    /** @param array<int, string> $overrides */
    private function read(array $overrides): \PDF\Html\Model\TaggedDocument
    {
        return (new ReadPdfSource())->read((new DocumentReader())->read(new FileBytes($this->pdf($overrides))), 'input.pdf');
    }
    /** @param list<SourceNode> $nodes */
    private function text(array $nodes): string
    {
        $text = '';
        foreach ($nodes as $node) {
            if ($node instanceof TextContent) {
                $text .= $node->text;
            } elseif ($node instanceof MarkedContent) {
                $text .= $this->text($node->children);
            }
        }
        return $text;
    }
    private function stream(string $bytes, string $entries = ''): string
    {
        return '<< /Length ' . strlen($bytes) . ' ' . $entries . ">>\nstream\n" . $bytes . "\nendstream";
    }
    /** @param array<int, string> $overrides */
    private function pdf(array $overrides): string
    {
        $objects = array_replace([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R >> >> /Contents 4 0 R >>',
            4 => $this->stream('/P <</MCID 0>> BDC BT /F1 12 Tf (Alpha) Tj ET EMC'),
            5 => '<< /Type /StructTreeRoot /K 6 0 R >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K 7 0 R >>',
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 >>',
            8 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ], $overrides);
        ksort($objects);
        $bytes = "%PDF-2.0\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $offset = strlen($bytes);
        $bytes .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($objects as $number => $object) {
            $bytes .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        return $bytes . 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\nstartxref\n" . $offset . "\n%%EOF\n";
    }
}
