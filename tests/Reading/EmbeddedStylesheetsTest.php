<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Reading;

use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\PdfToHtml;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TextContent;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\ReadingException;
use PDF\Html\Reading\ReadingLimits;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PHPUnit\Framework\TestCase;

final class EmbeddedStylesheetsTest extends TestCase
{
    public function testStrictCatalogPolicyStillUsesOnlyTheFirstHtmlSupplement(): void
    {
        $policy = new DerivationPolicy(allowEmbeddedStylesheets: true);
        $source = (new ReadPdfSource(policy: $policy))->read((new DocumentReader())->read($this->fixture()), 'strict.pdf');
        self::assertSame(['first.html'], array_map(static fn ($file) => $file->filename, $source->associatedFiles));
        $result = (new HtmlDeriver($policy))->derive($source);
        self::assertStringContainsString('p{color:red}', $result->html);
        self::assertStringNotContainsString('color:blue', $result->html);
    }

    public function testExplicitInteroperabilityMergesHtmlSupplementsInOrderOnly(): void
    {
        $policy = new DerivationPolicy(allowEmbeddedStylesheets: true, mergeCatalogHtmlSupplements: true);
        // The unrelated CSS stream has an unsupported codec and must not be decoded.
        $source = (new ReadPdfSource(policy: $policy))->read((new DocumentReader())->read($this->fixture()), 'interop.pdf');
        self::assertSame(['first.html', 'second.html'], array_map(static fn ($file) => $file->filename, $source->associatedFiles));
        $result = (new HtmlDeriver($policy))->derive($source);
        self::assertStringContainsString('p{color:red}', $result->html);
        self::assertStringContainsString('p{color:blue}', $result->html);
        self::assertLessThan(strpos($result->html, 'p{color:blue}'), strpos($result->html, 'p{color:red}'));
        self::assertStringNotContainsString('<script', $result->html);
        self::assertContains('catalog-html-supplements-merged', array_map(static fn ($diagnostic) => $diagnostic->code, $result->diagnostics));
    }

    public function testMergeOptionDoesNotEnableStylesOrScriptsByItself(): void
    {
        $policy = new DerivationPolicy(mergeCatalogHtmlSupplements: true);
        $result = (new PdfToHtml(deriver: new HtmlDeriver($policy)))->convert($this->fixture(), 'blocked.pdf');
        self::assertStringNotContainsString('color:red', $result->html);
        self::assertStringNotContainsString('color:blue', $result->html);
        self::assertStringNotContainsString('<script', $result->html);
    }

    public function testAllMergedSupplementBytesAreChargedToTheReadingBudget(): void
    {
        $this->expectException(ReadingException::class);
        (new ReadPdfSource(new ReadingLimits(maximumAssetsBytes: 70), new DerivationPolicy(allowEmbeddedStylesheets: true, mergeCatalogHtmlSupplements: true)))->read((new DocumentReader())->read($this->fixture()), 'budget.pdf');
    }

    public function testStylesheetAssociatedFileHasItsOwnCapabilityAndIsSanitized(): void
    {
        $fixture = new PdfFixture();
        $pdf = $fixture->build([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AF [15 0 R] >>',
            15 => '<< /Type /Filespec /UF (document.css) /AFRelationship /Supplement /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('/* source comment */ p{color:red;background:url(https://evil.test/x)}', '/Type /EmbeddedFile /Subtype /text#2Fcss'),
        ]);
        $blocked = (new PdfToHtml())->convert($pdf, 'blocked.pdf');
        self::assertCount(0, $blocked->assets);
        $result = (new PdfToHtml(deriver: new HtmlDeriver(new DerivationPolicy(allowEmbeddedStylesheets: true))))->convert($pdf, 'allowed.pdf');
        self::assertCount(1, $result->assets);
        self::assertSame('p{color:red}', $result->assets[0]->bytes);
        self::assertStringContainsString('href="document.css"', $result->html);
    }

    public function testStructureStylesheetRetainsItsLinkAndAppliesSupplementContentRules(): void
    {
        $fixture = new PdfFixture();
        $pdf = $fixture->build([
            4 => $fixture->stream('/P <</MCID 0>> BDC BT /F1 12 Tf (Direct content) Tj ET EMC /Span <</MCID 1>> BDC BT /F1 12 Tf (Child structure) Tj ET EMC'),
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K [0 10 0 R] /AF [15 0 R 17 0 R 19 0 R] >>',
            10 => '<< /Type /StructElem /S /Span /P 7 0 R /Pg 3 0 R /K 1 >>',
            14 => '<< /Nums [0 [7 0 R 10 0 R]] >>',
            15 => '<< /Type /Filespec /UF (local.css) /AFRelationship /Supplement /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('@import "https://evil.test/import.css";p{color:red;background:u/**/rl(https://evil.test/pixel)}', '/Type /EmbeddedFile /Subtype /text#2Fcss'),
            17 => '<< /Type /Filespec /FS /URL /F (https://evil.test/remote.css) /AFRelationship /Supplement >>',
            19 => '<< /Type /Filespec /UF (active.js) /AFRelationship /Supplement /EF << /UF 20 0 R >> >>',
            20 => $fixture->stream('globalThis.unwanted=true;', '/Type /EmbeddedFile /Subtype /text#2Fjavascript'),
        ]);
        $blocked = (new PdfToHtml())->convert($pdf, 'blocked.pdf');
        self::assertSame([], $blocked->assets);
        self::assertStringContainsString('Direct content', $blocked->html);
        self::assertStringContainsString('Child structure', $blocked->html);
        self::assertStringNotContainsString('<link', $blocked->html);

        $policy = new DerivationPolicy(allowEmbeddedStylesheets: true);
        $result = (new PdfToHtml(deriver: new HtmlDeriver($policy)))->convert($pdf, 'accepted.pdf');
        self::assertCount(1, $result->assets);
        self::assertSame('local.css', $result->assets[0]->filename);
        self::assertSame('p{color:red}', $result->assets[0]->bytes);
        self::assertStringContainsString('<link rel="stylesheet" type="text/css" href="local.css"><p', $result->html);
        self::assertStringNotContainsString('Direct content', $result->html);
        self::assertStringContainsString('Child structure', $result->html);
        self::assertStringNotContainsString('evil.test', $result->html);
        self::assertStringNotContainsString('<script', $result->html);
        $codes = array_map(static fn ($diagnostic) => $diagnostic->code, $result->diagnostics);
        foreach (['blocked-stylesheet-rule', 'blocked-css', 'associated-url-blocked', 'associated-script-blocked'] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertNotContains('blocked-output-element', $codes);
    }

    public function testOriginalLatexAttachmentRetainsBothStylesheetsAndFallbacks(): void
    {
        $path = dirname(__DIR__).'/Fixtures/latex-associated-styles/example.pdf';
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        self::assertSame('25c567628f155250e6b9b959fc93604a5b41fa2d79419e9b3ce6cb3981a684a1', hash('sha256', $bytes));
        $result = (new PdfToHtml(deriver: new HtmlDeriver(new DerivationPolicy(allowEmbeddedStylesheets: true, mergeCatalogHtmlSupplements: true))))->convert(new FileBytes($bytes), 'example.pdf');
        self::assertStringContainsString('li > span[data-pdf-se-type=Lbl]', $result->html);
        self::assertStringContainsString('mtable.alignat > mtr > mtd', $result->html);
        self::assertStringContainsString('text-align:right;text-align:-moz-right;text-align:-webkit-right', $result->html);
        self::assertStringContainsString('@supports (-webkit-appearance:none) and (stroke-color:transparent)', $result->html);
        self::assertStringContainsString('mtable.flalign{width:100%}', $result->html);
        self::assertStringNotContainsString('border    margin-left', $result->html);
        self::assertStringNotContainsString('<script', $result->html);
        self::assertContains('blocked-css', array_map(static fn ($diagnostic) => $diagnostic->code, $result->diagnostics));
    }

    public function testHtmlNamespaceStyleAttributeKeepsAllFallbacksThroughDerivation(): void
    {
        $style = 'text-align:right;text-align:-moz-right;text-align:-webkit-right';
        $document = new TaggedDocument('fallback.pdf', [new StructureElement('paragraph', 'P', [new TextContent('Fallbacks')], attributes: [new AttributeObject('HTML-5.00', ['style' => $style])])]);
        self::assertStringContainsString('style="'.$style.'"', (new HtmlDeriver())->derive($document)->html);
    }

    public function testClassHtmlStylesKeepFallbacksQuotedValuesAndCssOwnerPrecedence(): void
    {
        $document = new TaggedDocument('class-fallback.pdf', [new StructureElement('paragraph', 'P', [new TextContent('Fallbacks')], properties: new StructureProperties(classes: ['fallback']))], classMap: [
            'fallback' => [new AttributeObject('HTML-5.00', ['style' => 'text-align:right;text-align:-moz-right;text-align:-webkit-right;content:"a;color:red";color:blue!important']), new AttributeObject('CSS-3.00', ['color' => 'green'])],
        ]);
        $result = (new HtmlDeriver())->derive($document);
        self::assertStringContainsString('.fallback{text-align:right;text-align:-moz-right;text-align:-webkit-right;content:"a;color:red";color:green}', $result->css);
        self::assertStringNotContainsString('color:blue', $result->html);
        self::assertStringContainsString('text-align:right;text-align:-moz-right;text-align:-webkit-right', $result->html);
    }

    private function fixture(): FileBytes
    {
        $fixture = new PdfFixture();
        return $fixture->build([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /AF [15 0 R 17 0 R 19 0 R] >>',
            15 => '<< /Type /Filespec /UF (first.html) /AFRelationship /Supplement /EF << /UF 16 0 R >> >>',
            16 => $fixture->stream('<style>p{color:red}</style><script>alert(1)</script>', '/Type /EmbeddedFile /Subtype /text#2Fhtml'),
            17 => '<< /Type /Filespec /UF (ignored.css) /AFRelationship /Supplement /EF << /UF 18 0 R >> >>',
            18 => $fixture->stream('unsupported', '/Type /EmbeddedFile /Subtype /text#2Fcss /Filter /UnsupportedCodec'),
            19 => '<< /Type /Filespec /UF (second.html) /AFRelationship /Supplement /EF << /UF 20 0 R >> >>',
            20 => $fixture->stream('<style>p{color:blue}</style>', '/Type /EmbeddedFile /Subtype /text#2Fhtml'),
        ]);
    }
}
