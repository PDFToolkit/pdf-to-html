<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingLimits;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Reading\DocumentReader;
use PDF\Reading\UnsupportedPdfFeature;
use PHPUnit\Framework\TestCase;

final class AssociatedFilesReadingTest extends TestCase
{
    public function testAlternativePrecedesUnsupportedContentOnASharedPage(): void
    {
        $fixture = new PdfFixture();
        $bytes = $fixture->build([
            3 => '<< /Type /Page /Parent 2 0 R /StructParents 0 /MediaBox [0 0 200 200] /Resources << /Font << /F1 8 0 R /F2 9 0 R >> >> /Contents 4 0 R >>',
            4 => $fixture->stream('/P <</MCID 1>> BDC BT /F1 12 Tf (Sibling) Tj ET EMC /P <</MCID 0>> BDC BT /F2 12 Tf (S) Tj ET EMC'),
            6 => '<< /Type /StructElem /S /Document /P 5 0 R /K [10 0 R 7 0 R] >>',
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /AF [15 0 R] >>',
            9 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 13 0 R >>',
            10 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 1 >>',
            13 => $fixture->stream('1 begincodespacerange <0000> <ffff> endcodespacerange 1 beginbfchar <53> <0053> endbfchar'),
            14 => '<< /Nums [0 [7 0 R 10 0 R]] >>',
            15 => '<< /Type /Filespec /UF (replacement.html) /AFRelationship /Alternative /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('<p>Alternative</p>', '/Type /EmbeddedFile /Subtype /text#2Fhtml'),
        ]);
        $source = (new ReadPdfSource(structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read($bytes), 'alternative.pdf');
        $html = (new HtmlDeriver())->derive($source)->html;
        self::assertStringContainsString('Alternative', $html);
        self::assertStringContainsString('Sibling', $html);
        self::assertSame([], $source->sourceDiagnostics);
    }

    public function testSupplementSkipsOnlyDirectContentAndPreservesCaption(): void
    {
        $fixture = new PdfFixture();
        $bytes = $fixture->build([
            4 => $fixture->stream('/P <</MCID 1>> BDC BT /F1 12 Tf (Caption) Tj ET EMC'),
            7 => '<< /Type /StructElem /S /Figure /P 6 0 R /Pg 3 0 R /K [0 10 0 R] /AF [15 0 R] >>',
            10 => '<< /Type /StructElem /S /Caption /P 7 0 R /Pg 3 0 R /K 1 >>',
            14 => '<< /Nums [0 [7 0 R 10 0 R]] >>',
            15 => '<< /Type /Filespec /UF (supplement.html) /AFRelationship /Supplement /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('<span>Supplement</span>', '/Type /EmbeddedFile /Subtype /text#2Fhtml'),
        ]);
        $source = (new ReadPdfSource(structureValidation: StructureValidationMode::Strict))->read((new DocumentReader())->read($bytes), 'supplement.pdf');
        $html = (new HtmlDeriver())->derive($source)->html;
        self::assertStringContainsString('Supplement', $html);
        self::assertStringContainsString('Caption', $html);
    }

    public function testBlockedAlternativeDoesNotSuppressOriginalOrDecodeItsStream(): void
    {
        $fixture = new PdfFixture();
        $bytes = $fixture->build([
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /AF [15 0 R] >>',
            15 => '<< /Type /Filespec /UF (blocked.svg) /AFRelationship /Alternative /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('undecodable bytes', '/Type /EmbeddedFile /Subtype /image#2Fsvg+xml /Filter /UnsupportedCodec'),
        ]);
        $source = (new ReadPdfSource())->read((new DocumentReader())->read($bytes), 'safe.pdf');
        self::assertNull($source->structure[0]->children[0]->associatedFiles[0]->bytes);
        self::assertStringContainsString('Visible', (new HtmlDeriver())->derive($source)->html);
        $this->expectException(UnsupportedPdfFeature::class);
        (new ReadPdfSource(policy: new DerivationPolicy(allowActiveContent: true)))->read((new DocumentReader())->read($bytes), 'trusted.pdf');
    }

    public function testCatalogHtmlSupplementIgnoresOtherStreamsBeforeDecoding(): void
    {
        $fixture = new PdfFixture();
        $bytes = $fixture->build([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AF [15 0 R 17 0 R] >>',
            15 => '<< /Type /Filespec /UF (ignored.css) /AFRelationship /Supplement /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('bad codec', '/Type /EmbeddedFile /Subtype /text#2Fcss /Filter /UnsupportedCodec'),
            17 => '<< /Type /Filespec /UF (metadata.html) /AFRelationship /Supplement /EF << /UF 18 0 R >> >>',
            18 => $fixture->stream('<meta name="keywords" content="retained">', '/Type /EmbeddedFile /Subtype /text#2Fhtml'),
        ]);
        $policy = new DerivationPolicy(allowActiveContent: true);
        $source = (new ReadPdfSource(policy: $policy))->read((new DocumentReader())->read($bytes), 'head.pdf');
        self::assertCount(1, $source->associatedFiles);
        self::assertSame('metadata.html', $source->associatedFiles[0]->filename);
        self::assertStringContainsString('content="retained"', (new HtmlDeriver($policy))->derive($source)->html);
    }

    public function testEmbeddedFileTakesPrecedenceOverFsUrl(): void
    {
        $fixture = new PdfFixture();
        $bytes = $fixture->build([
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /AF [15 0 R] >>',
            15 => '<< /Type /Filespec /FS /URL /F (https://invalid.example/remote.html) /UF (embedded.html) /AFRelationship /Alternative /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('<p>Embedded</p>', '/Type /EmbeddedFile /Subtype /text#2Fhtml'),
        ]);
        $source = (new ReadPdfSource())->read((new DocumentReader())->read($bytes), 'embedded.pdf');
        self::assertNull($source->structure[0]->children[0]->associatedFiles[0]->url);
        self::assertStringContainsString('Embedded', (new HtmlDeriver())->derive($source)->html);
    }

    public function testReplacementAssetsAreChargedOnceAcrossBothPasses(): void
    {
        $fixture = new PdfFixture();
        $replacement = '<p>Replacement</p>';
        $bytes = $fixture->build([
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /AF [15 0 R] >>',
            15 => '<< /Type /Filespec /UF (replacement.html) /AFRelationship /Alternative /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream($replacement, '/Type /EmbeddedFile /Subtype /text#2Fhtml'),
        ]);
        $source = (new ReadPdfSource(new ReadingLimits(maximumAssetsBytes: strlen($replacement))))->read((new DocumentReader())->read($bytes), 'budget.pdf');
        self::assertSame($replacement, $source->structure[0]->children[0]->associatedFiles[0]->bytes);
    }

    public function testPrivateContentIsIgnoredBeforeUnsupportedStreamDecoding(): void
    {
        $fixture = new PdfFixture();
        $source = (new ReadPdfSource())->read((new DocumentReader())->read($fixture->build([
            4 => $fixture->stream('unreadable', '/Filter /UnsupportedCodec'),
            7 => '<< /Type /StructElem /S /Private /P 6 0 R /Pg 3 0 R /K 0 >>',
        ])), 'private.pdf');
        self::assertStringNotContainsString('unreadable', (new HtmlDeriver())->derive($source)->html);
    }

    public function testHtmlUrlRequiresBothRemoteAndActiveContentPolicies(): void
    {
        $fixture = new PdfFixture();
        $bytes = $fixture->build([
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /AF [15 0 R] >>',
            15 => '<< /Type /Filespec /FS /URL /F (https://example.test/alternative.html) /AFRelationship /Alternative >>',
        ]);
        $policy = new DerivationPolicy(allowRemoteResources: true);
        $source = (new ReadPdfSource(policy: $policy))->read((new DocumentReader())->read($bytes), 'blocked-import.pdf');
        $result = (new HtmlDeriver($policy))->derive($source);
        self::assertStringContainsString('Visible', $result->html);
        self::assertStringNotContainsString('rel="import"', $result->html);
    }
}
