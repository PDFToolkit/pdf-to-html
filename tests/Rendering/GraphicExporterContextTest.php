<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Rendering;

use PDF\Html\DerivationException;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicProgram;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\StructureElement;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Rendering\GraphicPdfExporter;
use PDF\Html\Rendering\GraphicsExportLimits;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\TestCase;

final class GraphicExporterContextTest extends TestCase
{
    public function testUnselectedFormTextKeepsTheInheritedClippingMode(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('inherited-text-clip'))->bytes;
        self::assertStringContainsString("7 Tr\n(B) Tj\n7 Tr", $bytes);
        self::assertStringNotContainsString("3 Tr\n(B) Tj", $bytes);
    }

    public function testAnArtifactInsideSelectedMarkedContentDoesNotPaint(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('nested-artifact'))->bytes;
        self::assertStringContainsString("20 20 re\nf", $bytes);
        self::assertStringNotContainsString("200 200 re\nf", $bytes);
    }

    public function testInstructionLimitIsCumulativeAcrossTheCallerAndForm(): void
    {
        $group = $this->group('inherited-text-clip');
        $marked = $group->children[0];
        self::assertInstanceOf(MarkedContent::class, $marked);
        self::assertInstanceOf(GraphicProgram::class, $marked->source);
        self::assertInstanceOf(GraphicProgram::class, $marked->source->parent);
        $limit = max(count($marked->source->instructions), count($marked->source->parent->instructions));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('instruction');
        (new GraphicPdfExporter(new GraphicsExportLimits(maximumInstructions: $limit)))->export($group);
    }

    public function testIndirectResourceDictionariesRespectTheDepthLimit(): void
    {
        $this->expectException(DerivationException::class);
        (new GraphicPdfExporter(new GraphicsExportLimits(maximumDepth: 2)))->export($this->group('deep-resource'));
    }

    public function testAValidPdf17StandardFontIsNotSilentlyUpgradedToPdf20(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('pdf17-standard-font'))->bytes;
        self::assertStringStartsWith('%PDF-1.7', $bytes);
        self::assertStringContainsString('/BaseFont /Helvetica', $bytes);
    }

    public function testAnExplicitNullExternalFileKeyIsEquivalentToItsAbsence(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('form-null-external-file'))->bytes;
        self::assertStringContainsString('/Subtype /Form', $bytes);
    }

    public function testTheSourcePageTransparencyGroupIsRetained(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('page-transparency-group'))->bytes;
        self::assertStringContainsString('/Group << /Type /Group /S /Transparency /CS /DeviceCMYK /I true /K false >>', $bytes);
        self::assertStringContainsString('/ca 0.5', $bytes);
    }

    public function testOptionalContentKeepsItsMembershipAndDefaultVisibility(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('hidden-optional-content'))->bytes;
        self::assertStringContainsString('/OCProperties', $bytes);
        self::assertStringContainsString('/OFF [', $bytes);
        self::assertStringContainsString('/Type /OCG', $bytes);
        self::assertStringContainsString("/OC /Hidden BDC\n", $bytes);
    }

    private function group(string $name): ContentGroup
    {
        $file = (new RenderingReviewCases())->documents()[$name];
        $document = (new ReadPdfSource())->read((new DocumentReader())->read($file), 'graphics-review.pdf');
        $figure = $document->structure[0]->children[0];
        self::assertInstanceOf(StructureElement::class, $figure);
        return new ContentGroup($figure->children);
    }

    public function testNullPageGroupAndOptionalContentAreEquivalentToAbsentEntries(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('null-page-context'))->bytes;
        self::assertStringNotContainsString('/Group null', $bytes);
        self::assertStringNotContainsString('/OCProperties null', $bytes);
    }
}
