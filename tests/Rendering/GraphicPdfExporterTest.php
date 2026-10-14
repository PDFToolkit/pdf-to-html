<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Rendering;

use PDF\Html\DerivationException;
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Rendering\GraphicPdfExporter;
use PDF\Html\Rendering\GraphicsExportLimits;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PHPUnit\Framework\TestCase;

final class GraphicPdfExporterTest extends TestCase
{
    public function testIsolatedGraphicsPreserveClippingButSuppressOtherPaints(): void
    {
        $group = $this->group('clipped-path');
        $rendering = (new GraphicPdfExporter())->export($group);
        $reader = (new DocumentReader())->read(new FileBytes($rendering->bytes));
        $page = (new PageTreeReader($reader))->read()->pages[0];
        self::assertSame([0, 0, 200, 200], array_map(static fn ($number) => $number->value, $page->attributes->mediaBox->items));
        self::assertSame(200.0, $rendering->widthPoints);
        self::assertSame(200.0, $rendering->heightPoints);
        self::assertStringContainsString("W\nn\n1 0 0 rg", $rendering->bytes);
        self::assertStringNotContainsString("200 200 re\nf", $rendering->bytes);
        self::assertStringContainsString("100 100 re\nf", $rendering->bytes);
        self::assertNull($reader->catalog()->get('OpenAction'));
        self::assertNull($reader->catalog()->get('AcroForm'));
    }

    public function testInvisibleUnselectedTextStillAdvancesTheTextMatrix(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('text-position'))->bytes;
        self::assertStringContainsString("3 Tr\n(AAA) Tj\n0 Tr", $bytes);
        self::assertStringContainsString('(B) Tj', $bytes);
        (new DocumentReader())->read(new FileBytes($bytes));
    }

    public function testNestedPartialFormKeepsItsCallerAndSelectsOnlyItsMcid(): void
    {
        $bytes = (new GraphicPdfExporter())->export($this->group('inherited-form'))->bytes;
        self::assertStringContainsString("20 20 cm", $bytes);
        self::assertStringContainsString("1 0 0 rg", $bytes);
        self::assertSame(1, substr_count($bytes, "100 100 re\nf"));
        self::assertStringContainsString('/Matrix [1 0 0 1 5 5]', $bytes);
    }

    public function testRotationAndUserUnitAreAppliedExactlyOnce(): void
    {
        $rendering = (new GraphicPdfExporter())->export($this->group('rotation-unit'));
        self::assertSame(160.0, $rendering->widthPoints);
        self::assertSame(200.0, $rendering->heightPoints);
        self::assertStringContainsString('/MediaBox [0 -200 160 0]', $rendering->bytes);
        self::assertStringContainsString("0 -2 2 0 0 0 cm", $rendering->bytes);
    }

    public function testAnExplicitSourceViewportControlsTheLogicalFigureSize(): void
    {
        $group = $this->group('rotation-unit');
        $rendering = (new GraphicPdfExporter())->export(new ContentGroup($group->children, viewport: new \PDF\Html\Model\GraphicViewport(10, 10, 30, 30)));
        self::assertSame(40.0, $rendering->widthPoints);
        self::assertSame(40.0, $rendering->heightPoints);
        self::assertStringContainsString('/MediaBox [20 -60 60 -20]', $rendering->bytes);
    }

    public function testResourceAndByteLimitsFailExplicitly(): void
    {
        $this->expectException(DerivationException::class);
        (new GraphicPdfExporter(new GraphicsExportLimits(maximumBytes: 16)))->export($this->group('clipped-path'));
    }

    public function testDescriptionsCannotMasqueradeAsGraphicsPrograms(): void
    {
        $this->expectException(DerivationException::class);
        (new GraphicPdfExporter())->export(new GraphicContent('path', 'S'));
    }

    public function testIndependentSourceDocumentsCannotCollideOnObjectNumbers(): void
    {
        $one = $this->group('clipped-path');
        $two = $this->group('clipped-path');
        $this->expectException(DerivationException::class);
        (new GraphicPdfExporter())->export(new ContentGroup([...$one->children, ...$two->children]));
    }

    public function testVisualProfileNeverSilentlyDegradesToSemanticOutput(): void
    {
        $this->expectException(DerivationException::class);
        (new HtmlDeriver(new DerivationPolicy(preferVisualFigures: true)))->derive(new TaggedDocument('visual.pdf', [new StructureElement('figure', 'Figure', [new TextContent('Visual')])]));
    }

    private function group(string $name): ContentGroup
    {
        $file = (new RenderingCases())->documents()[$name];
        $document = (new ReadPdfSource())->read((new DocumentReader())->read($file), 'graphics.pdf');
        return new ContentGroup($document->structure[0]->children[0]->children);
    }
}
