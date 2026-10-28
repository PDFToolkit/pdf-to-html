<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\Model\Widget;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\TestCase;

final class FormActionsReadingTest extends TestCase
{
    public function testMalformedHighlightingDoesNotSilentlySelectTheDefault(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('highlighting mode must be a PDF name');
        $this->widgets([9 => '<< /Type /Annot /Subtype /Widget /StructParent 2 /P 3 0 R /Rect [0 30 80 50] /FT /Btn /Ff 65536 /T (Action) /H (I) >>']);
    }

    public function testPrimaryJavaScriptActionAndItsChainBelongToTheClickedWidget(): void
    {
        [, $button] = $this->widgets([
            15 => '<< /S /JavaScript /JS (first\(\);) /Next << /S /JavaScript /JS (second\(\);) >> >>',
        ]);
        self::assertSame("first();\n;\nsecond();", $button->actions['A']);
        self::assertSame([], $button->field->actions);
    }

    public function testDefaultValueAndResetSelectionUseInheritedQualifiedNames(): void
    {
        [$text, $button] = $this->widgets();
        self::assertSame('Group.Name', $text->field->name);
        self::assertSame('current', $text->field->value);
        self::assertSame('default', $text->field->defaultValue);
        self::assertNotNull($button->field->resetAction);
        self::assertSame(['Group', 'Group.Name'], $button->field->resetAction->selection->fields);
        self::assertTrue($button->field->resetAction->selection->exclude);
    }

    public function testAbsentDefaultNeverFallsBackToTheCurrentValue(): void
    {
        [$text] = $this->widgets([11 => '<< /FT /Tx /T (Group) /Kids [12 0 R] >>']);
        self::assertSame('current', $text->field->value);
        self::assertNull($text->field->defaultValue);
    }

    public function testExplicitEmptyFieldsDiffersFromAnAbsentFieldsEntry(): void
    {
        [, $empty] = $this->widgets([15 => '<< /S /ResetForm /Fields [] >>']);
        [, $all] = $this->widgets([15 => '<< /S /ResetForm >>']);
        self::assertSame([], $empty->field->resetAction?->selection->fields);
        self::assertNull($all->field->resetAction?->selection->fields);
    }

    public function testSubmitSelectionAndMappingNameAreRecovered(): void
    {
        [$text, $button] = $this->widgets([
            11 => '<< /FT /Tx /T (Group) /DV (default) /TM (mapped-name) /Kids [12 0 R] >>',
            15 => '<< /S /SubmitForm /F << /FS /URL /F (https://example.test/submit) >> /Fields [11 0 R] /Flags 5 >>',
        ]);
        self::assertSame('mapped-name', $text->field->mappingName);
        self::assertNotNull($button->field->submitAction);
        self::assertSame('https://example.test/submit', $button->field->submitAction->target);
        self::assertSame(['Group'], $button->field->submitAction->selection->fields);
        self::assertTrue($button->field->submitAction->selection->exclude);
    }

    public function testFieldSelectionCycleIsRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('name inheritance contains a cycle');
        $this->widgets([
            15 => '<< /S /ResetForm /Fields [16 0 R] >>',
            16 => '<< /T (cycle) /Parent 16 0 R >>',
        ]);
    }

    public function testInvalidFieldsSelectionIsRejected(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('Fields selection must be an array');
        $this->widgets([15 => '<< /S /ResetForm /Fields (Group) >>']);
    }

    /** @param array<int, string|null> $overrides @return array{Widget, Widget} */
    private function widgets(array $overrides = []): array
    {
        $fixture = new PdfFixture();
        $objects = array_replace([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AcroForm << /Fields [11 0 R 9 0 R] >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources <<>> /Contents 4 0 R /Annots [12 0 R 9 0 R] >>',
            4 => $fixture->stream(''),
            5 => '<< /Type /StructTreeRoot /K 6 0 R /ParentTree 14 0 R /ParentTreeNextKey 3 >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 10 0 R] >>',
            7 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 12 0 R >> >>',
            9 => '<< /Type /Annot /Subtype /Widget /StructParent 2 /P 3 0 R /Rect [0 30 80 50] /FT /Btn /Ff 65536 /T (Action) /A 15 0 R >>',
            10 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 9 0 R >> >>',
            11 => '<< /FT /Tx /T (Group) /DV (default) /Kids [12 0 R] >>',
            12 => '<< /Type /Annot /Subtype /Widget /StructParent 1 /P 3 0 R /Rect [0 0 80 20] /Parent 11 0 R /T (Name) /V (current) >>',
            14 => '<< /Nums [1 7 0 R 2 10 0 R] >>',
            15 => '<< /S /ResetForm /Fields [(Group) 12 0 R] /Flags 1 >>',
        ], $overrides);
        $source = (new ReadPdfSource(structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read($fixture->build($objects)), 'actions.pdf');
        $text = $source->structure[0]->children[0]->children[0];
        $button = $source->structure[0]->children[1]->children[0];
        self::assertInstanceOf(Widget::class, $text);
        self::assertInstanceOf(Widget::class, $button);
        return [$text, $button];
    }
}
