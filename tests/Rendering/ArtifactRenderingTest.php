<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Rendering;

use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\Widget;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Rendering\GraphicPdfExporter;
use PDF\Html\Tests\Reading\PdfFixture;
use PDF\Reading\DocumentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtifactRenderingTest extends TestCase
{
    #[DataProvider('nestedPrograms')]
    public function testNestedFormArtifactsAreFilteredEvenWhenNoVisibleContentRemains(string $program, bool $visible): void
    {
        $fixture = new PdfFixture();
        $file = (new AppearanceCases())->document([
            10 => $fixture->stream('/Fm Do', '/Type /XObject /Subtype /Form /BBox [0 0 30 15] /Resources << /XObject << /Fm 18 0 R >> >>'),
            18 => $fixture->stream($program, '/Type /XObject /Subtype /Form /BBox [0 0 30 15] /Resources << >>'),
        ]);
        $source = (new ReadPdfSource())->read((new DocumentReader())->read($file), 'nested-artifact.pdf');
        $widget = $source->structure[0]->children[0]->children[0];
        self::assertInstanceOf(Widget::class, $widget);
        $export = (new GraphicPdfExporter())->export(new ContentGroup($widget->appearance));
        self::assertSame([60.0, 30.0], [$export->widthPoints, $export->heightPoints]);
        $document = (new DocumentReader())->read(new \PDF\Reading\FileBytes($export->bytes));
        self::assertCount(1, (new \PDF\Reading\PageTreeReader($document))->read()->pages);
        self::assertStringNotContainsString('/Artifact', $export->bytes);
        self::assertStringContainsString("0 0 30 15 re\nn\n", $export->bytes);
        self::assertSame($visible, str_contains($export->bytes, "5 5 5 5 re\nf\n"));
    }

    public static function nestedPrograms(): iterable
    {
        yield 'artifact and visible path' => ['/Artifact BMC 1 0 0 rg 0 0 30 15 re f EMC 0 0 1 rg 5 5 5 5 re f', true];
        yield 'only artifact' => ['/Artifact BMC 1 0 0 rg 0 0 30 15 re f EMC', false];
        yield 'marked parent and nested artifact' => ['/Span BMC /Artifact BMC 1 0 0 rg 0 0 30 15 re f EMC 0 0 1 rg 5 5 5 5 re f EMC', true];
    }
}
