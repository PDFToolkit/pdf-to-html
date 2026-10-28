<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Rendering;

use PDF\Html\AssetCollection;
use PDF\Html\ContentRenderer;
use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\Html\Element;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\ImageContent;
use PDF\Html\Model\Widget;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Html\Rendering\ContentAppearanceRenderer;
use PDF\Html\Rendering\GraphicsRasterizer;
use PDF\Html\Rendering\GraphicPdfExporter;
use PDF\Html\Rendering\RasterContentRenderer;
use PDF\Html\Rendering\RenderingDocument;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentAppearanceRendererTest extends TestCase
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function testNativeStateAppearancesShareTheExistingGraphicsPipeline(): void
    {
        $widget = $this->widget();
        self::assertSame(['On'], $widget->appearanceStates);
        self::assertNotEmpty($widget->appearance);
        self::assertNotEmpty($widget->downAppearance);
        self::assertNotEmpty($widget->rolloverAppearance);
        $rasterizer = new class () implements GraphicsRasterizer {
            /** @var list<RenderingDocument> */
            public array $documents = [];
            public function rasterize(RenderingDocument $document): ImageContent
            {
                $this->documents[] = $document;
                return new ImageContent(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true), 'image/gif', 1, 1);
            }
        };
        $assets = new AssetCollection(new DerivationLimits());
        $renderer = new ContentAppearanceRenderer(new RasterContentRenderer($rasterizer), assets: $assets);
        foreach ([$widget->appearance, $widget->downAppearance, $widget->rolloverAppearance] as $appearance) {
            $nodes = $renderer->render($appearance);
            self::assertCount(1, $nodes);
            self::assertInstanceOf(Element::class, $nodes[0]);
            self::assertSame('img', $nodes[0]->tag);
            self::assertSame('80', $nodes[0]->attributes['width']);
            self::assertSame('40', $nodes[0]->attributes['height']);
            self::assertSame('data:image/gif;base64,' . self::GIF, $nodes[0]->attributes['src']);
        }
        self::assertCount(3, $rasterizer->documents);
        foreach (['Normal', 'Down', 'Rollover'] as $index => $caption) {
            self::assertStringContainsString('(' . $caption . ') Tj', $rasterizer->documents[$index]->bytes);
            self::assertSame(60.0, $rasterizer->documents[$index]->widthPoints);
            self::assertSame(30.0, $rasterizer->documents[$index]->heightPoints);
            self::assertStringContainsString('/BBox [0 0 30 15]', $rasterizer->documents[$index]->bytes);
            self::assertStringNotContainsString('/AcroForm', $rasterizer->documents[$index]->bytes);
        }
        self::assertCount(1, $assets->all());
    }

    #[DataProvider('absentStates')]
    public function testUnmatchedOrAbsentAppearanceStateDoesNotPickAnArbitraryStream(string $state): void
    {
        $widget = $this->widget([9 => '<< /Type /Annot /Subtype /Widget /StructParent 0 /P 3 0 R /Rect [0 0 30 15] /FT /Btn /Ff 65536 /T (Action) ' . $state . ' /AP << /N << /Off 13 0 R /On 10 0 R >> /D << /On 11 0 R >> /R << /On 12 0 R >> >> >>']);
        self::assertSame([], $widget->appearance);
        self::assertSame([], $widget->downAppearance);
        self::assertSame([], $widget->rolloverAppearance);
    }

    public static function absentStates(): iterable
    {
        yield 'unmatched' => ['/AS /Missing'];
        yield 'absent' => [''];
    }

    public function testDirectStreamsDoNotRequireAppearanceStateAndNullStatesRemainAbsent(): void
    {
        $widget = $this->widget([9 => '<< /Type /Annot /Subtype /Widget /StructParent 0 /P 3 0 R /Rect [0 0 60 30] /FT /Btn /Ff 65536 /T (Action) /AP << /N 10 0 R /D null /R null >> >>']);
        self::assertNotEmpty($widget->appearance);
        self::assertSame([], $widget->downAppearance);
        self::assertSame([], $widget->rolloverAppearance);
    }

    public function testSharedAppearanceIsPlacedIndependentlyInEachWidgetRectangle(): void
    {
        $source = (new ReadPdfSource(structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read((new AppearanceCases())->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AcroForm << /Fields [9 0 R 17 0 R] >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 4 0 R /Annots [9 0 R 17 0 R] >>',
            5 => '<< /Type /StructTreeRoot /K 6 0 R /ParentTree 14 0 R /ParentTreeNextKey 2 >>',
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [7 0 R 16 0 R] >>',
            16 => '<< /Type /StructElem /S /Form /P 6 0 R /Pg 3 0 R /K << /Type /OBJR /Obj 17 0 R >> >>',
            17 => '<< /Type /Annot /Subtype /Widget /StructParent 1 /P 3 0 R /Rect [30 40 150 100] /FT /Btn /Ff 65536 /T (Second) /AP << /N 10 0 R >> >>',
            14 => '<< /Nums [0 7 0 R 1 16 0 R] >>',
        ])), 'shared-appearance.pdf');
        $first = $source->structure[0]->children[0]->children[0];
        $second = $source->structure[0]->children[1]->children[0];
        self::assertInstanceOf(Widget::class, $first);
        self::assertInstanceOf(Widget::class, $second);
        $exporter = new GraphicPdfExporter();
        $one = $exporter->export(new ContentGroup($first->appearance));
        $two = $exporter->export(new ContentGroup($second->appearance));
        self::assertSame([60.0, 30.0], [$one->widthPoints, $one->heightPoints]);
        self::assertSame([120.0, 60.0], [$two->widthPoints, $two->heightPoints]);
        self::assertStringContainsString('/MediaBox [10 20 70 50]', $one->bytes);
        self::assertStringContainsString('/MediaBox [30 40 150 100]', $two->bytes);
    }

    public function testAppearancePlacementIncludesPageRotationAndUserUnitOnce(): void
    {
        $widget = $this->widget([3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Rotate 90 /UserUnit 2 /Resources << >> /Contents 4 0 R /Annots [9 0 R] >>']);
        $document = (new GraphicPdfExporter())->export(new ContentGroup($widget->appearance));
        self::assertSame([60.0, 120.0], [$document->widthPoints, $document->heightPoints]);
        self::assertStringContainsString('/MediaBox [40 -140 100 -20]', $document->bytes);
    }

    public function testRepeatedNestedFormsHaveTheirOwnAppearanceInvocationContexts(): void
    {
        $fixture = new \PDF\Html\Tests\Reading\PdfFixture();
        $widget = $this->widget([
            10 => $fixture->stream('q /Fm Do Q q 2 0 0 1 10 0 cm /Fm Do Q', '/Type /XObject /Subtype /Form /BBox [0 0 30 15] /Resources << /XObject << /Fm 18 0 R >> >>'),
            18 => $fixture->stream('1 0 0 rg 0 0 10 10 re f', '/Type /XObject /Subtype /Form /BBox [0 0 10 10] /Resources << >>'),
        ]);
        $document = (new GraphicPdfExporter())->export(new ContentGroup($widget->appearance));
        self::assertSame([60.0, 30.0], [$document->widthPoints, $document->heightPoints]);
        self::assertSame(2, substr_count($document->bytes, '/Fm Do'));
    }

    public function testMalformedSelectedStateFailsExplicitly(): void
    {
        $this->expectException(ReadingException::class);
        $this->expectExceptionMessage('selected widget appearance must resolve to a stream');
        $this->widget([10 => '(not a stream)']);
    }

    public function testNullEngineResultCannotSilentlyDiscardAnAppearance(): void
    {
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('could not render');
        $this->renderer(null)->render($this->widget()->appearance);
    }

    #[DataProvider('invalidImages')]
    public function testInvalidOrUnboundedEngineOutputIsRejected(ImageContent $image, string $message): void
    {
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage($message);
        $this->renderer($image)->render($this->widget()->appearance);
    }

    public static function invalidImages(): iterable
    {
        yield 'mime mismatch' => [new ImageContent(base64_decode(self::GIF, true), 'image/png', 30, 15), 'declared image type'];
        yield 'missing bytes' => [new ImageContent(null, 'image/png', 30, 15), 'portable raster'];
        yield 'active media' => [new ImageContent('<svg onload="alert(1)"/>', 'image/svg+xml', 30, 15), 'portable raster'];
        yield 'infinite dimension' => [new ImageContent('', 'image/png', INF, 15), 'logical dimensions'];
        yield 'negative dimension' => [new ImageContent('', 'image/png', -1, 15), 'logical dimensions'];
        yield 'unrepresentable pixels' => [new ImageContent('', 'image/png', PHP_FLOAT_MAX, 15), 'logical dimensions'];
    }

    public function testSharedDocumentAssetBudgetAccumulatesAcrossAppearances(): void
    {
        $bytes = base64_decode(self::GIF, true);
        $limits = new DerivationLimits(maximumAssetBytes: strlen($bytes));
        $renderer = $this->renderer(new ImageContent($bytes, 'image/gif', 30, 15), $limits, new AssetCollection($limits));
        $renderer->render($this->widget()->appearance);
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('cumulative derived asset limit');
        $renderer->render($this->widget()->appearance);
    }

    public function testEncodedOutputBudgetIsCheckedBeforeReturningTheDataUrl(): void
    {
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('encoded appearance exceeds');
        $this->renderer(new ImageContent(base64_decode(self::GIF, true), 'image/gif', 30, 15), new DerivationLimits(maximumOutputBytes: 8))->render($this->widget()->appearance);
    }

    public function testEmptyAppearancesNeedNoEngineCall(): void
    {
        self::assertSame([], $this->renderer(null)->render([]));
    }

    /** @param array<int, string|null> $overrides */
    private function widget(array $overrides = []): Widget
    {
        $source = (new ReadPdfSource(structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read((new AppearanceCases())->document($overrides)), 'appearance.pdf');
        $widget = $source->structure[0]->children[0]->children[0];
        self::assertInstanceOf(Widget::class, $widget);
        return $widget;
    }

    private function renderer(?ImageContent $image, DerivationLimits $limits = new DerivationLimits(), ?AssetCollection $assets = null): ContentAppearanceRenderer
    {
        return new ContentAppearanceRenderer(new class ($image) implements ContentRenderer {
            public function __construct(private readonly ?ImageContent $image)
            {
            }
            public function render(GraphicContent|ContentGroup $content): ?ImageContent
            {
                return $this->image;
            }
        }, $limits, $assets);
    }
}
