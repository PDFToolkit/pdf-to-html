<?php

declare(strict_types=1);

namespace PDF\Html\Tests;

use PDF\Html\DerivationPolicy;
use PDF\Html\Forms\FieldType;
use PDF\Html\Forms\FormActionPolicy;
use PDF\Html\Forms\ResetFormAction;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\FormField;
use PDF\Html\Model\LinkAnnotation;
use PDF\Html\Model\RoleMapping;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;
use PDF\Html\PdfToHtml;
use PDF\Html\Tests\Reading\PdfFixture;
use PHPUnit\Framework\TestCase;

final class NormativeDerivationTest extends TestCase
{
    public function testTableBorderStylePreservesPdfSideOrderInCss(): void
    {
        $html = (new HtmlDeriver())->derive(new TaggedDocument('table.pdf', [new StructureElement('table', 'Table', attributes: [
            new AttributeObject('Table', ['TBorderStyle' => ['Solid', 'Dashed', 'Dotted', 'Double']]),
        ])]))->html;
        self::assertStringContainsString('border-style:solid double dashed dotted', $html);
    }

    public function testReferenceSkipsTargetlessLinksAndRecognizesRoleMappedLinks(): void
    {
        $reference = new StructureElement('reference', 'Reference', [
            new StructureElement('empty', 'Link', [new TextContent('No target')]),
            new StructureElement('mapped', 'CustomLink', [new LinkAnnotation(uri: 'https://example.test/target'), new TextContent('Target')]),
        ]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('reference.pdf', [$reference], roleMappings: [new RoleMapping('CustomLink', null, 'Link')]))->html;
        self::assertMatchesRegularExpression('/<a[^>]+data-pdf-se-type="Reference"[^>]+href="https:\/\/example.test\/target"/', $html);
    }

    public function testInlineTextualFigureRetainsIdentityLanguageAltAndExplicitAttributes(): void
    {
        $figure = new StructureElement('figure', 'Figure', [new TextContent('∑')], properties: new StructureProperties(id: 'symbol', language: 'fr', alternateDescription: 'Somme'), attributes: [
            new AttributeObject('HTML-5.00', ['title' => 'Une somme']), new AttributeObject('CSS-3.00', ['color' => 'red']),
        ]);
        $html = (new HtmlDeriver())->derive(new TaggedDocument('inline.pdf', [new StructureElement('p', 'P', [$figure])]))->html;
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $content = $dom->getElementById('symbol');
        self::assertInstanceOf(\DOMElement::class, $content);
        self::assertSame('∑', $content->textContent);
        self::assertSame('fr', $content->getAttribute('lang'));
        self::assertSame('Somme', $content->getAttribute('aria-label'));
        self::assertSame('img', $content->getAttribute('role'));
        self::assertSame('Une somme', $content->getAttribute('title'));
        self::assertSame('color:red', $content->getAttribute('style'));
    }

    public function testAlternativeStylesheetReplacesContentAndSupplementPreservesSubstructure(): void
    {
        foreach (['Alternative' => false, 'Supplement' => true] as $relationship => $captionRemains) {
            $source = new StructureElement('figure', 'Figure', [new TextContent('Ignored direct content'), new StructureElement('caption', 'Caption', [new TextContent('Caption text')])], associatedFiles: [new AssociatedFile('figure.css', 'text/css', $relationship, 'figure { color: red; }')]);
            $result = (new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)))->derive(new TaggedDocument('associated.pdf', [$source]));
            self::assertStringNotContainsString('Ignored direct content', $result->html);
            self::assertSame($captionRemains, str_contains($result->html, 'Caption text'));
            self::assertStringContainsString('<link rel="stylesheet" type="text/css" href="figure.css">', $result->html);
            self::assertSame('figure{color:red}', $result->assets[0]->bytes);
            if ($captionRemains) {
                self::assertLessThan(strpos($result->html, '<figure'), strpos($result->html, '<link'));
            }
        }
    }

    public function testAutomaticSourceInterpretationSharesTheDerivationPolicy(): void
    {
        $fixture = new PdfFixture();
        $file = $fixture->build([
            4 => $fixture->stream('/P <</MCID 0>> BDC BX MalformedForNormalExtraction EX EMC'),
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /AF [9 0 R] >>',
            9 => '<< /Type /Filespec /UF (replacement.js) /F (replacement.js) /AFRelationship /Alternative /EF << /F 10 0 R >> >>',
            10 => $fixture->stream('const replacement = true;', '/Type /EmbeddedFile /Subtype /text#2Fjavascript'),
        ]);
        $result = (new PdfToHtml(deriver: new HtmlDeriver(new DerivationPolicy(allowActiveContent: true))))->convert($file, 'policy.pdf');
        self::assertStringNotContainsString('MalformedForNormalExtraction', $result->html);
        self::assertCount(1, $result->assets);
        self::assertSame('const replacement = true;', $result->assets[0]->bytes);
    }

    public function testIndirectCatalogVersionSelectsPdf20Utf8TextStrings(): void
    {
        $file = (new PdfFixture())->build([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R /Version 20 0 R /Lang <EFBBBF66722D4652> >>',
            7 => '<< /Type /StructElem /S /P /P 6 0 R /Pg 3 0 R /K 0 /ActualText <EFBBBF426F6E6A6F757220C3A974C3A9> >>',
            20 => '/2.0',
        ]);
        $file = new \PDF\Reading\FileBytes(str_replace('%PDF-2.0', '%PDF-1.7', $file->bytes));
        $html = (new PdfToHtml())->convert($file, 'version.pdf')->html;
        self::assertStringContainsString('lang="fr-FR"', $html);
        self::assertStringContainsString('Bonjour été', $html);
    }

    public function testSafeResetPolicyEmitsOnlyLibraryOwnedRuntime(): void
    {
        $name = new Widget(new FormField('Name', FieldType::Text, value: 'Current', defaultValue: 'Default', actions: ['V' => 'document.cookie = "unsafe";']));
        $reset = new Widget(new FormField('Reset', FieldType::Button, 65536, resetAction: new ResetFormAction(['Name'])));
        $document = new TaggedDocument('form.pdf', [new StructureElement('name', 'Form', [$name]), new StructureElement('reset', 'Form', [$reset])], documentScripts: ['throw new Error("document source");']);
        $result = (new HtmlDeriver(new DerivationPolicy(formActions: new FormActionPolicy(allowReset: true))))->derive($document);
        self::assertStringContainsString('type="reset"', $result->html);
        self::assertCount(1, $result->assets);
        self::assertStringContainsString('Default', $result->assets[0]->bytes);
        self::assertStringNotContainsString('document.cookie', $result->assets[0]->bytes);
        self::assertStringNotContainsString('document source', $result->assets[0]->bytes);
        self::assertContains('scripts-disabled', array_column($result->diagnostics, 'code'));
    }

    public function testExplicitNativeAdapterCannotApplyADifferentReplacementPolicy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdfToHtml(source: new \PDF\Html\Reading\ReadPdfSource(), deriver: new HtmlDeriver(new DerivationPolicy(allowActiveContent: true)));
    }

    public function testTableHeadersTargetFinalHtmlIdsAndRespectExplicitHtmlHeaders(): void
    {
        foreach ([false, true] as $explicit) {
            $attributes = [new AttributeObject('Table', ['Headers' => ['pdf-header']])];
            if ($explicit) {
                $attributes[] = new AttributeObject('HTML-5.00', ['headers' => 'html-header']);
            }
            $document = new TaggedDocument('table.pdf', [new StructureElement('table', 'Table', [new StructureElement('row', 'TR', [
                new StructureElement('header', 'TH', [new TextContent('Header')], properties: new StructureProperties(id: 'pdf-header'), attributes: [new AttributeObject('HTML-5.00', ['id' => 'html-header'])]),
                new StructureElement('cell', 'TD', [new TextContent('Cell')], attributes: $attributes),
            ])])]);
            $html = (new HtmlDeriver())->derive($document)->html;
            self::assertStringContainsString('headers="html-header"', $html);
            self::assertStringNotContainsString('headers="pdf-header"', $html);
        }
    }

    public function testStructuredDestinationsFollowAnAlternativeHtmlIdInEitherReadingOrder(): void
    {
        $target = new StructureElement('target', 'P', [new TextContent('Ignored')], properties: new StructureProperties(id: 'source-id'), associatedFiles: [
            new AssociatedFile('alternative.html', 'text/html', 'Alternative', '<p id="embedded-id">Alternative</p>'),
        ]);
        $link = new StructureElement('link', 'Link', [new LinkAnnotation(structureTarget: 'target'), new TextContent('Go')]);
        foreach ([[$target, $link], [$link, $target]] as $order) {
            $html = (new HtmlDeriver())->derive(new TaggedDocument('links.pdf', $order))->html;
            self::assertStringContainsString('id="embedded-id"', $html);
            self::assertStringContainsString('href="#embedded-id"', $html);
            self::assertStringNotContainsString('href="#source-id"', $html);
        }
    }

    public function testTableHeadersFollowAlternativeIdsWithoutRewritingExplicitHtmlHeaders(): void
    {
        foreach ([false, true] as $explicit) {
            $attributes = [new AttributeObject('Table', ['Headers' => ['pdf-header']])];
            if ($explicit) {
                $attributes[] = new AttributeObject('HTML-5.00', ['headers' => 'source-id']);
            }
            $header = new StructureElement('header', 'TH', [new TextContent('Ignored')], properties: new StructureProperties(id: 'pdf-header'), attributes: [new AttributeObject('HTML-5.00', ['id' => 'source-id'])], associatedFiles: [
                new AssociatedFile('header.html', 'text/html', 'Alternative', '<th id="embedded-id">Header</th>'),
            ]);
            $document = new TaggedDocument('table.pdf', [new StructureElement('table', 'Table', [new StructureElement('row', 'TR', [
                new StructureElement('cell', 'TD', [new TextContent('Cell')], attributes: $attributes), $header,
            ])])]);
            $html = (new HtmlDeriver())->derive($document)->html;
            self::assertStringContainsString('headers="'.($explicit ? 'source-id' : 'embedded-id').'"', $html);
        }
    }

    public function testAnAlternativeWithoutAContentElementRetainsItsStructureDestination(): void
    {
        foreach (['text/plain' => false, 'text/html' => false, 'text/css' => true, 'text/javascript' => true] as $type => $active) {
            $target = new StructureElement('target', 'P', [new TextContent('PDF content')], properties: new StructureProperties(id: 'source-id'), associatedFiles: [
                new AssociatedFile('alternative.'.($type === 'text/css' ? 'css' : ($type === 'text/javascript' ? 'js' : 'html')), $type, 'Alternative', $type === 'text/css' ? 'p {color: red;}' : ($type === 'text/javascript' ? 'const marker = true;' : 'Plain alternative text')),
            ]);
            $link = new StructureElement('link', 'Link', [new LinkAnnotation(structureTarget: 'target'), new TextContent('Go')]);
            $html = (new HtmlDeriver(new DerivationPolicy(allowActiveContent: $active)))->derive(new TaggedDocument('links.pdf', [$link, $target]))->html;
            self::assertStringContainsString('id="source-id"', $html);
            self::assertStringContainsString('href="#source-id"', $html);
        }
    }

    public function testPageIdentifierBelongsToActualContentAndFormExpansionPreservesItsFieldId(): void
    {
        $widget = new Widget(new FormField('Name', FieldType::Text, value: 'Ada'), page: 2);
        $document = new TaggedDocument('pages.pdf', [
            new StructureElement('paragraph', 'P', [new TextContent('Page text', 1)]),
            new StructureElement('form', 'Form', [new StructureElement('label', 'Lbl', [new TextContent('Name')]), $widget], properties: new StructureProperties(id: 'person', expansion: 'Expanded name'), attributes: [new AttributeObject('HTML-5.00', ['title' => 'Preferred title'])]),
        ]);
        $html = (new HtmlDeriver())->derive($document)->html;
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $page = $dom->getElementById('PDF-Page-1');
        self::assertInstanceOf(\DOMElement::class, $page);
        self::assertSame('Page text', $page->textContent);
        $field = $dom->getElementById('person');
        self::assertInstanceOf(\DOMElement::class, $field);
        self::assertSame('abbr', $field->parentNode->nodeName);
        self::assertSame('Preferred title', $field->parentNode->attributes->getNamedItem('title')->nodeValue);
        self::assertStringContainsString('for="person"', $html);
    }
}
