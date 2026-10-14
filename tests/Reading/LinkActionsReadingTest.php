<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\Model\LinkAnnotation;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\TestCase;

final class LinkActionsReadingTest extends TestCase
{
    public function testFirstUriOrGotoIsSelectedDepthFirstWithoutExecutingEarlierActions(): void
    {
        $source = $this->read([
            15 => '<< /S /JavaScript /JS (throw new Error\("never execute"\);) /Next [16 0 R 17 0 R] >>',
            16 => '<< /S /Named /N /Print /Next << /S /GoTo /D [3 0 R /Fit] >> >>',
            17 => '<< /S /URI /URI (https://example.test/second) >>',
        ]);
        self::assertSame(1, $this->annotation($source)->pageTarget);
        self::assertNull($this->annotation($source)->uri);
        $html = (new \PDF\Html\HtmlDeriver())->derive($source)->html;
        self::assertStringContainsString('href="#PDF-Page-1"', $html);
        self::assertStringNotContainsString('never execute', $html);
        self::assertStringNotContainsString('https://example.test/second', $html);
    }

    public function testMatchingInitialActionDoesNotInterpretLaterActions(): void
    {
        $source = $this->read([15 => '<< /S /URI /URI (https://example.test/first) /Next 15 0 R >>']);
        self::assertSame('https://example.test/first', $this->annotation($source)->uri);
    }

    public function testGotoPrefersStructureDestinationAndPreservesBinaryId(): void
    {
        $source = $this->read([
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /ID <ff00> >>',
            15 => '<< /S /GoTo /D [3 0 R /Fit] /SD [7 0 R /Fit] >>',
        ]);
        self::assertSame('obj-7-0', $this->annotation($source)->structureTarget);
        $html = (new \PDF\Html\HtmlDeriver())->derive($source)->html;
        self::assertStringContainsString('id="pdf-id-ff00"', $html);
        self::assertStringContainsString('href="#pdf-id-ff00"', $html);
    }

    public function testNamedDestinationDictionaryPrefersSdOverD(): void
    {
        $source = $this->read([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Names << /Dests 18 0 R >> >>',
            15 => '<< /S /GoTo /D (named) >>',
            18 => '<< /Names [(named) << /D [3 0 R /Fit] /SD [7 0 R /Fit] >>] >>',
        ]);
        self::assertSame('obj-7-0', $this->annotation($source)->structureTarget);
    }

    public function testNameAndByteStringDestinationNamespacesDoNotCollide(): void
    {
        $catalog = '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Dests << /same [3 0 R /Fit] >> /Names << /Dests 18 0 R >> >>';
        $tree = '<< /Names [(same) << /SD [7 0 R /Fit] /D [3 0 R /Fit] >>] >>';
        $name = $this->read([1 => $catalog, 15 => '<< /S /GoTo /D /same >>', 18 => $tree]);
        $string = $this->read([1 => $catalog, 15 => '<< /S /GoTo /D (same) >>', 18 => $tree]);
        self::assertSame(1, $this->annotation($name)->pageTarget);
        self::assertSame('obj-7-0', $this->annotation($string)->structureTarget);
    }

    public function testActionCycleBeforeNavigationIsRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('action sequence contains a cycle');
        $this->read([15 => '<< /S /Named /N /Print /Next 16 0 R >>', 16 => '[15 0 R]']);
    }

    public function testSharedAcyclicActionIsAllowedAndStillBounded(): void
    {
        $source = $this->read([
            15 => '<< /S /Named /N /Print /Next [16 0 R 16 0 R 17 0 R] >>',
            16 => '<< /S /Named /N /Print >>',
            17 => '<< /S /URI /URI (https://example.test/final) >>',
        ]);
        self::assertSame('https://example.test/final', $this->annotation($source)->uri);
    }

    public function testDeepActionChainsRespectTraversalBudget(): void
    {
        $objects = [];
        for ($number = 15; $number < 25; ++$number) {
            $objects[$number] = '<< /S /Named /N /Print /Next ' . ($number + 1) . ' 0 R >>';
        }
        $objects[25] = '<< /S /URI /URI (https://example.test/deep) >>';
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('traversal depth');
        $this->read($objects, new ReadingLimits(maximumDepth: 6));
    }

    public function testStructureDestinationCannotPointOutsideActualTree(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('must belong to this source structure tree');
        $this->read([
            15 => '<< /S /GoTo /SD [16 0 R /Fit] /D [3 0 R /Fit] >>',
            16 => '<< /Type /StructElem /S /P /P 5 0 R >>',
        ]);
    }

    /** @param array<int, string|null> $overrides */
    private function read(array $overrides = [], ReadingLimits $limits = new ReadingLimits()): TaggedDocument
    {
        $objects = array_replace([
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R >> >> /Contents 4 0 R /Annots [12 0 R] >>',
            5 => '<< /Type /StructTreeRoot /K 6 0 R /ParentTree 14 0 R /ParentTreeNextKey 2 >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 10 0 R] >>',
            10 => '<< /Type /StructElem /S /Link /P 6 0 R /Pg 3 0 R /K [<< /Type /OBJR /Obj 12 0 R >>] >>',
            12 => '<< /Type /Annot /Subtype /Link /P 3 0 R /StructParent 1 /Rect [0 0 100 20] /A 15 0 R >>',
            14 => '<< /Nums [0 [7 0 R] 1 10 0 R] >>',
            15 => '<< /S /GoTo /D [3 0 R /Fit] >>',
        ], $overrides);
        return (new ReadPdfSource($limits, structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read((new PdfFixture())->build($objects)), 'links.pdf');
    }

    private function annotation(TaggedDocument $source): LinkAnnotation
    {
        $annotation = $source->structure[0]->children[1]->children[0];
        self::assertInstanceOf(LinkAnnotation::class, $annotation);
        return $annotation;
    }
}
