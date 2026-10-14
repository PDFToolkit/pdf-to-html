<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\Model\TaggedDocument;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\TestCase;

final class ParentTreeReadingTest extends TestCase
{
    public function testStrictModeChecksPageAnnotationAndFormMcrAssociations(): void
    {
        $source = $this->read($this->mixedObjects());
        self::assertSame([], $source->sourceDiagnostics);
        self::assertCount(3, $source->structure[0]->children);
    }

    public function testWrongInverseParentIsRejectedInStrictMode(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('does not point to its forward parent');
        $this->read([14 => '<< /Nums [0 [6 0 R]] >>']);
    }

    public function testPermissiveModeReportsInconsistencyWithoutLosingContent(): void
    {
        $source = $this->read([14 => '<< /Nums [0 [6 0 R]] >>'], StructureValidationMode::Permissive);
        self::assertNotEmpty($source->sourceDiagnostics);
        self::assertStringContainsString('ParentTree key 0 MCID 0', implode(' ', $source->sourceDiagnostics));
        self::assertStringContainsString('Visible', (new \PDF\Html\HtmlDeriver())->derive($source)->html);
    }

    public function testReverseOnlyMcidIsRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('has no matching forward association');
        $this->read([14 => '<< /Nums [0 [7 0 R 7 0 R]] >>']);
    }

    public function testMissingParentTreeIsExplicitInBothModes(): void
    {
        $objects = [5 => '<< /Type /StructTreeRoot /K 6 0 R >>', 14 => null];
        $source = $this->read($objects, StructureValidationMode::Permissive);
        self::assertStringContainsString('no ParentTree', implode(' ', $source->sourceDiagnostics));
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('no ParentTree');
        $this->read($objects);
    }

    public function testDistinctContainersCannotReuseAParentTreeKey(): void
    {
        $objects = $this->mixedObjects();
        $objects[17] = str_replace('/StructParents 2', '/StructParents 0', $objects[17]);
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('shared by distinct content containers');
        $this->read($objects);
    }

    public function testAnnotationMustHaveItsStructParent(): void
    {
        $objects = $this->mixedObjects();
        $objects[12] = str_replace('/StructParent 1', '', $objects[12]);
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('requires a nonnegative /StructParent');
        $this->read($objects);
    }

    public function testNumberTreeCycleCannotBeHiddenByPermissiveMode(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('cycle or repeated node');
        $this->read([14 => '<< /Kids [15 0 R] >>', 15 => '<< /Kids [14 0 R] >>'], StructureValidationMode::Permissive);
    }

    public function testDuplicateNumberTreeKeysAreRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('must be unique');
        $this->read([14 => '<< /Nums [0 [7 0 R] 0 [7 0 R]] >>']);
    }

    public function testInverseTraversalRemainsCumulativelyBounded(): void
    {
        $fixture = new PdfFixture();
        $bytes = $fixture->build([14 => '<< /Nums [0 [7 0 R ' . str_repeat('null ', 200) . ']] >>']);
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('source nodes budget');
        (new ReadPdfSource(new ReadingLimits(maximumNodes: 100), structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read($bytes), 'bounded.pdf');
    }

    /** @param array<int, string|null> $objects */
    private function read(array $objects = [], StructureValidationMode $mode = StructureValidationMode::Strict): TaggedDocument
    {
        return (new ReadPdfSource(structureValidation: $mode))->read((new DocumentReader())->read((new PdfFixture())->build($objects)), 'inverse.pdf');
    }

    /** @return array<int, string> */
    private function mixedObjects(): array
    {
        $fixture = new PdfFixture();
        return [
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R >> /XObject << /Fm 17 0 R >> >> /Contents 4 0 R /Annots [12 0 R] >>',
            4 => $fixture->stream('/P <</MCID 0>> BDC BT /F1 12 Tf (Visible) Tj ET EMC /Fm Do'),
            5 => '<< /Type /StructTreeRoot /K 6 0 R /ParentTree 14 0 R /ParentTreeNextKey 3 >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 10 0 R 11 0 R] >>',
            10 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 12 0 R >> >>',
            11 => '<< /Type /StructElem /S /Span /P 6 0 R /Pg 3 0 R /K << /Type /MCR /MCID 2 /Stm 17 0 R >> >>',
            12 => '<< /Type /Annot /Subtype /Widget /StructParent 1 /P 3 0 R /Rect [0 0 50 20] /FT /Tx /T (Name) /V (value) >>',
            14 => '<< /Nums [0 [7 0 R] 1 10 0 R 2 [null null 11 0 R]] >>',
            17 => $fixture->stream('/Span <</MCID 2>> BDC BT /F1 12 Tf (Form text) Tj ET EMC', '/Type /XObject /Subtype /Form /BBox [0 0 20 20] /StructParents 2'),
        ];
    }
}
